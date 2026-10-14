<?php
namespace SmtpHub;

class ServerRepository
{
    private $modx;
    private $table;
    private $availabilityTable;
    private $tableSuffix;
    private $crypto;
    public function __construct($modx, $tableSuffix = 'smtphub_servers')
    {
        $this->modx = $modx;
        $prefix = $modx->getOption('table_prefix', null, 'modx_');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new \RuntimeException('Unsupported table prefix.'); }
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $tableSuffix)) { throw new \RuntimeException('Unsupported table name.'); }
        $this->table = '`' . $prefix . $tableSuffix . '`';
        $this->tableSuffix = $tableSuffix;
        $this->availabilityTable = '`' . $prefix . $tableSuffix . '_availability`';
        $this->crypto = new Crypto($modx);
    }

    public function initialize()
    {
        $initialize = require __DIR__ . '/storage.php';
        $initialize($this->modx, $this->tableSuffix);
        $this->crypto->initialize();
    }

    private function sql($query, array $values = [])
    {
        $statement = $this->modx->prepare($query);
        if (!$statement || !$statement->execute($values)) { throw new \RuntimeException('SMTPHub database operation failed.'); }
        return $statement;
    }

    public function listing()
    {
        $rows = $this->sql('SELECT s.id,s.name,s.priority,s.enabled,s.host,s.port,s.secure,s.auth,s.username,s.timeout,s.auto_tls,s.revision,s.sender_email,s.sender_name,
            s.password_cipher,(s.password_cipher <> "") AS has_password,
            a.available,a.checked_at,a.connection_hash FROM ' . $this->table . ' s LEFT JOIN ' . $this->availabilityTable . ' a ON a.server_id=s.id
            WHERE s.deleted=0 ORDER BY s.priority,s.id')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['id', 'priority', 'port', 'timeout', 'revision'] as $key) { $row[$key] = (int) $row[$key]; }
            foreach (['enabled', 'auth', 'auto_tls', 'has_password'] as $key) { $row[$key] = (bool) $row[$key]; }
            $row['availability'] = $row['checked_at'] === null ? null : [
                'available' => (bool) $row['available'], 'checked_at' => gmdate('c', (int) $row['checked_at']),
                'message' => AvailabilityProbe::message((bool) $row['available']),
                'stale' => $row['connection_hash'] !== $this->connectionHash($row)];
            unset($row['password_cipher'], $row['available'], $row['checked_at'], $row['connection_hash']);
        }
        return $rows;
    }

    public function profiles()
    {
        $rows = $this->sql('SELECT * FROM ' . $this->table . ' WHERE deleted=0 AND enabled=1 ORDER BY priority,id')->fetchAll(\PDO::FETCH_ASSOC);
        $profiles = [];
        foreach ($rows as $row) {
            $profiles[] = ['id' => 'server_' . $row['id'], 'host' => $row['host'], 'port' => (int) $row['port'],
                'secure' => $row['secure'], 'auth' => (bool) $row['auth'], 'username' => $row['username'],
                'password' => $this->crypto->decrypt($row['password_cipher']), 'timeout' => (int) $row['timeout'],
                'auto_tls' => (bool) $row['auto_tls'], 'sender_email' => $row['sender_email'] ?? '', 'sender_name' => $row['sender_name'] ?? ''];
        }
        return Profiles::validate($profiles);
    }

    private function row($id)
    {
        $row = $this->sql('SELECT * FROM ' . $this->table . ' WHERE id=? AND deleted=0', [$id])->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { throw new \InvalidArgumentException('SMTP-сервер не найден. Обновите список.'); }
        return $row;
    }

    public function profileForCheck($id, $revision)
    {
        return $this->profileForUse($id, $revision, true);
    }

    public function profileForUse($id, $revision, $onlyEnabled = false)
    {
        $id = $this->number($id, 1, 2147483647, 'ID');
        $revision = $this->number($revision, 1, 2147483647, 'Версия записи');
        $row = $this->row($id);
        if ((int) $row['revision'] !== $revision) {
            throw new \InvalidArgumentException('Сервер уже изменён. Обновите список.');
        }
        if ($onlyEnabled && !$row['enabled']) { return null; }
        return Profiles::validate([['id' => 'server_' . $id, 'host' => $row['host'], 'port' => (int) $row['port'],
            'secure' => $row['secure'], 'auth' => (bool) $row['auth'], 'username' => $row['username'],
            'password' => $this->crypto->decrypt($row['password_cipher']), 'timeout' => (int) $row['timeout'],
            'auto_tls' => (bool) $row['auto_tls'], 'sender_email' => $row['sender_email'] ?? '', 'sender_name' => $row['sender_name'] ?? '']])[0];
    }

    private function connectionHash(array $row)
    {
        return hash('sha256', serialize([strtolower($row['host']), (int) $row['port'], $row['secure'],
            (bool) $row['auth'], $row['username'], $row['password_cipher'], (int) $row['timeout'], (bool) $row['auto_tls']]));
    }

    public function saveAvailability($id, $revision, array $result)
    {
        $id = $this->number($id, 1, 2147483647, 'ID');
        $revision = $this->number($revision, 1, 2147483647, 'Версия записи');
        if (!isset($result['available']) || !is_bool($result['available'])) { throw new \InvalidArgumentException('Некорректный результат проверки.'); }
        $timestamp = isset($result['checked_at']) ? strtotime($result['checked_at']) : false;
        if ($timestamp === false || $timestamp < 1) { throw new \InvalidArgumentException('Некорректная дата проверки.'); }
        $this->sql('START TRANSACTION');
        try {
            $row = $this->sql('SELECT * FROM ' . $this->table . ' WHERE id=? AND deleted=0 FOR UPDATE', [$id])->fetch(\PDO::FETCH_ASSOC);
            if (!$row || !$row['enabled'] || (int) $row['revision'] !== $revision) {
                throw new \InvalidArgumentException('Сервер уже изменён. Обновите список.');
            }
            // An older concurrent probe must not overwrite a newer result.
            $this->sql('INSERT INTO ' . $this->availabilityTable . ' (server_id,available,checked_at,connection_hash) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE available=IF(VALUES(checked_at)>=checked_at,VALUES(available),available),
                connection_hash=IF(VALUES(checked_at)>=checked_at,VALUES(connection_hash),connection_hash),
                checked_at=GREATEST(checked_at,VALUES(checked_at))', [$id, (int) $result['available'], $timestamp, $this->connectionHash($row)]);
            $this->sql('COMMIT');
        } catch (\Throwable $e) { $this->sql('ROLLBACK'); throw $e; }
    }

    public static function boolean($value)
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    private function number($value, $min, $max, $label)
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new \InvalidArgumentException('Проверьте поле «' . $label . '».');
        }
        return (int) $value;
    }

    public function save(array $input)
    {
        $id = $this->number($input['id'] ?? 0, 0, 2147483647, 'ID');
        $old = $id ? $this->row($id) : null;
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128) { throw new \InvalidArgumentException('Укажите название сервера (до 128 символов).'); }
        $password = (string) ($input['password'] ?? '');
        if (strlen($password) > 4096) { throw new \InvalidArgumentException('Пароль слишком длинный.'); }
        $cipher = $old ? $old['password_cipher'] : '';
        if ($password !== '') { $cipher = $this->crypto->encrypt($password); }
        if (self::boolean($input['clear_password'] ?? false)) { $cipher = ''; }
        foreach (['sender_email', 'sender_name'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) {
                throw new \InvalidArgumentException('Проверьте поля отправителя.');
            }
        }
        $profile = ['id' => 'validation', 'host' => trim((string) ($input['host'] ?? '')),
            'port' => $this->number($input['port'] ?? 587, 1, 65535, 'Порт'),
            'secure' => (string) ($input['secure'] ?? 'tls'), 'auth' => self::boolean($input['auth'] ?? false),
            'username' => trim((string) ($input['username'] ?? '')), 'password' => $this->crypto->decrypt($cipher),
            'timeout' => $this->number($input['timeout'] ?? 5, 1, 60, 'Таймаут'), 'auto_tls' => self::boolean($input['auto_tls'] ?? false),
            'sender_email' => trim((string) ($input['sender_email'] ?? ($old['sender_email'] ?? ''))),
            'sender_name' => trim((string) ($input['sender_name'] ?? ($old['sender_name'] ?? '')))];
        if (strlen($profile['sender_email']) > 254 || ($profile['sender_email'] !== '' &&
            !filter_var($profile['sender_email'], FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Укажите корректный email отправителя или оставьте поле пустым.');
        }
        if (mb_strlen($profile['sender_name']) > 128 || preg_match('/[\x00-\x1f\x7f]/', $profile['sender_name'])) {
            throw new \InvalidArgumentException('Имя отправителя: до 128 символов, без переносов строк.');
        }
        if ($profile['auth'] && ($profile['username'] === '' || $profile['password'] === '')) {
            throw new \InvalidArgumentException('Для авторизации заполните логин и пароль. При редактировании пустой пароль сохраняет прежний.');
        }
        try { Profiles::validate([$profile]); }
        catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('Проверьте адрес SMTP-сервера и тип шифрования. Адрес задаётся без протокола и порта.'); }
        if (strlen($profile['host']) > 255 || strlen($profile['username']) > 255) { throw new \InvalidArgumentException('Адрес или логин слишком длинный.'); }
        // Ordering belongs to the list, never to the edit form. New rows append.
        $priority = $old ? (int) $old['priority'] : 0;
        $values = [$name, $priority,
            (int) self::boolean($input['enabled'] ?? false), $profile['host'], $profile['port'], $profile['secure'],
            (int) $profile['auth'], $profile['username'], $cipher, $profile['timeout'], (int) $profile['auto_tls'],
            $profile['sender_email'], $profile['sender_name']];
        if ($old) {
            $values[] = $id; $values[] = $this->number($input['revision'] ?? 0, 1, 2147483647, 'Версия записи');
            $result = $this->sql('UPDATE ' . $this->table . ' SET name=?,priority=?,enabled=?,host=?,port=?,secure=?,auth=?,username=?,
                password_cipher=?,timeout=?,auto_tls=?,sender_email=?,sender_name=?,revision=revision+1 WHERE id=? AND revision=? AND deleted=0', $values);
            if ($result->rowCount() !== 1) { throw new \InvalidArgumentException('Сервер уже изменён другим пользователем. Обновите список.'); }
        } else {
            $this->sql('START TRANSACTION');
            try {
                // Serialize appends with reorders so a simultaneous addition
                // cannot land halfway through a freshly renumbered list.
                $priorities = $this->sql('SELECT priority FROM ' . $this->table . ' WHERE deleted=0 ORDER BY priority DESC,id DESC FOR UPDATE')->fetchAll(\PDO::FETCH_COLUMN);
                $values[1] = ($priorities ? (int) $priorities[0] : 0) + 10;
                $this->sql('INSERT INTO ' . $this->table . ' (name,priority,enabled,host,port,secure,auth,username,password_cipher,timeout,auto_tls,sender_email,sender_name)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', $values);
                $this->sql('COMMIT');
            } catch (\Throwable $e) { $this->sql('ROLLBACK'); throw $e; }
        }
    }

    public function remove($id, $revision)
    {
        $id = $this->number($id, 1, 2147483647, 'ID');
        $revision = $this->number($revision, 1, 2147483647, 'Версия записи');
        $statement = $this->sql('UPDATE ' . $this->table . ' SET deleted=1,enabled=0,revision=revision+1 WHERE id=? AND revision=? AND deleted=0', [$id, $revision]);
        if ($statement->rowCount() !== 1) { throw new \InvalidArgumentException('Сервер уже изменён. Обновите список.'); }
    }

    public function duplicate($id, $revision)
    {
        $id = $this->number($id, 1, 2147483647, 'ID');
        $revision = $this->number($revision, 1, 2147483647, 'Версия записи');
        $row = $this->row($id);
        if ((int) $row['revision'] !== $revision) { throw new \InvalidArgumentException('Сервер уже изменён. Обновите список.'); }
        $suffix = ' (копия)';
        $this->save(['id' => 0, 'name' => mb_substr($row['name'], 0, 128 - mb_strlen($suffix)) . $suffix,
            'enabled' => (bool) $row['enabled'], 'host' => $row['host'], 'port' => (int) $row['port'],
            'secure' => $row['secure'], 'auth' => (bool) $row['auth'], 'username' => $row['username'],
            'password' => $this->crypto->decrypt($row['password_cipher']), 'timeout' => (int) $row['timeout'],
            'auto_tls' => (bool) $row['auto_tls'], 'sender_email' => $row['sender_email'], 'sender_name' => $row['sender_name']]);
    }

    private function importKey(array $row)
    {
        return hash('sha256', serialize([strtolower($row['host']), (int) $row['port'], $row['secure'],
            (bool) $row['auth'], $row['username'], $row['password'], (int) $row['timeout'], (bool) $row['auto_tls']]));
    }

    public function import(array $profiles)
    {
        $profiles = Profiles::validate($profiles);
        $incoming = [];
        foreach ($profiles as $profile) {
            $name = (string) ($profile['name'] ?? 'SMTP MODX');
            if ($name === '' || mb_strlen($name) > 128 || strlen($profile['host']) > 255 ||
                strlen($profile['username']) > 255 || strlen($profile['password']) > 4096) {
                throw new \InvalidArgumentException('SMTP-настройки MODX превышают допустимую длину полей SMTPHub. Импорт не выполнен.');
            }
            $profile['name'] = $name;
            $profile['password_cipher'] = $this->crypto->encrypt($profile['password']);
            $key = $this->importKey($profile);
            if (!isset($incoming[$key])) { $incoming[$key] = $profile; }
        }
        $this->sql('START TRANSACTION');
        try {
            $rows = $this->sql('SELECT * FROM ' . $this->table . ' WHERE deleted=0 ORDER BY priority,id FOR UPDATE')->fetchAll(\PDO::FETCH_ASSOC);
            $known = [];
            foreach ($rows as $row) {
                $row['password'] = $this->crypto->decrypt($row['password_cipher']);
                $key = $this->importKey($row);
                if (!isset($known[$key])) { $known[$key] = (int) $row['id']; }
            }
            $front = []; $added = 0;
            foreach ($incoming as $key => $profile) {
                if (isset($known[$key])) {
                    $id = $known[$key];
                    $this->sql('UPDATE ' . $this->table . ' SET enabled=1,revision=revision+1 WHERE id=? AND enabled<>1', [$id]);
                } else {
                    $this->sql('INSERT INTO ' . $this->table . ' (name,priority,enabled,host,port,secure,auth,username,password_cipher,timeout,auto_tls)
                        VALUES (?,0,1,?,?,?,?,?,?,?,?)', [$profile['name'], $profile['host'], $profile['port'], $profile['secure'],
                            (int) $profile['auth'], $profile['username'], $profile['password_cipher'], $profile['timeout'], (int) $profile['auto_tls']]);
                    $id = (int) $this->sql('SELECT LAST_INSERT_ID()')->fetchColumn();
                    $added++;
                }
                $front[] = $id;
            }
            $order = $front;
            foreach ($rows as $row) { if (!in_array((int) $row['id'], $front, true)) { $order[] = (int) $row['id']; } }
            foreach ($order as $rank => $id) {
                $priority = ($rank + 1) * 10;
                $this->sql('UPDATE ' . $this->table . ' SET priority=?,revision=revision+1 WHERE id=? AND priority<>?', [$priority, $id, $priority]);
            }
            $this->sql('COMMIT');
            return ['added' => $added, 'reused' => count($front) - $added];
        } catch (\Throwable $e) { $this->sql('ROLLBACK'); throw $e; }
    }

    public function toggle($id, $revision, $enabled)
    {
        $id = $this->number($id, 1, 2147483647, 'ID');
        $revision = $this->number($revision, 1, 2147483647, 'Версия записи');
        $result = $this->sql('UPDATE ' . $this->table . ' SET enabled=?,revision=revision+1 WHERE id=? AND revision=? AND deleted=0',
            [(int) self::boolean($enabled), $id, $revision]);
        if ($result->rowCount() !== 1) { throw new \InvalidArgumentException('Сервер уже изменён. Обновите список.'); }
    }

    public function reorder(array $items)
    {
        $requested = [];
        foreach ($items as $item) {
            if (!is_array($item)) { throw new \InvalidArgumentException('Некорректный порядок серверов.'); }
            $id = $this->number($item['id'] ?? 0, 1, 2147483647, 'ID');
            if (isset($requested[$id])) { throw new \InvalidArgumentException('Сервер повторяется в списке.'); }
            $requested[$id] = $this->number($item['revision'] ?? 0, 1, 2147483647, 'Версия записи');
        }
        $this->sql('START TRANSACTION');
        try {
            $rows = $this->sql('SELECT id,revision FROM ' . $this->table . ' WHERE deleted=0 ORDER BY priority,id FOR UPDATE')->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) !== count($requested)) { throw new \InvalidArgumentException('Список серверов изменился. Обновите его перед перетаскиванием.'); }
            foreach ($rows as $row) {
                if (!isset($requested[$row['id']]) || $requested[$row['id']] !== (int) $row['revision']) {
                    throw new \InvalidArgumentException('Список серверов изменился. Обновите его перед перетаскиванием.');
                }
            }
            $rank = 0;
            foreach ($requested as $id => $revision) {
                $this->sql('UPDATE ' . $this->table . ' SET priority=?,revision=revision+1 WHERE id=?', [(++$rank) * 10, $id]);
            }
            $this->sql('COMMIT');
        } catch (\Throwable $e) { $this->sql('ROLLBACK'); throw $e; }
    }

    public function move($id, $direction)
    {
        $id = $this->number($id, 1, 2147483647, 'ID');
        if (!in_array($direction, ['up', 'down'], true)) { throw new \InvalidArgumentException('Неизвестное направление.'); }
        $this->sql('START TRANSACTION');
        try {
            $rows = $this->sql('SELECT id FROM ' . $this->table . ' WHERE deleted=0 ORDER BY priority,id FOR UPDATE')->fetchAll(\PDO::FETCH_COLUMN);
            $index = array_search($id, array_map('intval', $rows), true);
            if ($index === false) { throw new \InvalidArgumentException('SMTP-сервер не найден.'); }
            $next = $index + ($direction === 'up' ? -1 : 1);
            if (isset($rows[$next])) { [$rows[$index], $rows[$next]] = [$rows[$next], $rows[$index]]; }
            foreach ($rows as $rank => $rowId) { $this->sql('UPDATE ' . $this->table . ' SET priority=?,revision=revision+1 WHERE id=?', [($rank + 1) * 10, $rowId]); }
            $this->sql('COMMIT');
        } catch (\Throwable $e) { $this->sql('ROLLBACK'); throw $e; }
    }
}
