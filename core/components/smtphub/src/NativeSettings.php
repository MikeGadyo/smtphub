<?php
namespace SmtpHub;

/** Reads global system settings only; never imports manager/user/context overrides. */
class NativeSettings
{
    private $modx;
    public function __construct($modx) { $this->modx = $modx; }

    private function read($key, $default)
    {
        $class = class_exists('MODX\\Revolution\\modSystemSetting') ? 'MODX\\Revolution\\modSystemSetting' : 'modSystemSetting';
        $setting = $this->modx->getObject($class, $key);
        return $setting ? $setting->get('value') : $default;
    }

    public function apply(array $profile)
    {
        $profile = Profiles::validate([$profile])[0];
        $secureKey = (int) $this->modx->getVersionData()['version'] >= 3 ? 'mail_smtp_secure' : 'mail_smtp_prefix';
        $values = ['mail_use_smtp' => '1', 'mail_smtp_hosts' => $profile['host'], 'mail_smtp_port' => (string) $profile['port'],
            $secureKey => $profile['secure'], 'mail_smtp_auth' => $profile['auth'] ? '1' : '0',
            'mail_smtp_user' => $profile['username'], 'mail_smtp_pass' => $profile['password'],
            'mail_smtp_timeout' => (string) $profile['timeout'], 'mail_smtp_autotls' => $profile['auto_tls'] ? '1' : '0'];
        $class = class_exists('MODX\\Revolution\\modSystemSetting') ? 'MODX\\Revolution\\modSystemSetting' : 'modSystemSetting';
        $settings = []; $original = [];
        foreach ($values as $key => $value) {
            $setting = $this->modx->getObject($class, $key);
            if (!$setting) { throw new \InvalidArgumentException('Не найдена системная настройка MODX «' . $key . '». Настройки не скопированы.'); }
            $settings[$key] = $setting;
            $original[$key] = $setting->get('value');
        }
        if (!$this->modx->beginTransaction()) { throw new \RuntimeException('Cannot begin SMTP settings transaction.'); }
        $changed = [];
        try {
            foreach ($values as $key => $value) {
                $changed[] = $key;
                $settings[$key]->set('value', $value);
                if (!$settings[$key]->save()) { throw new \RuntimeException('Cannot save SMTP system setting.'); }
            }
            if (!$this->modx->commit()) { throw new \RuntimeException('Cannot commit SMTP settings transaction.'); }
        } catch (\Throwable $e) {
            try { $this->modx->rollBack(); } catch (\Throwable $rollbackError) {}
            // Some old MODX installations use MyISAM for system settings.
            // Restore touched values there too, rather than relying only on rollback.
            $restored = true;
            foreach ($changed as $key) {
                try {
                    $settings[$key]->set('value', $original[$key]);
                    if (!$settings[$key]->save()) { $restored = false; }
                } catch (\Throwable $restoreError) { $restored = false; }
            }
            if (!$restored) { throw new \RuntimeException('Cannot restore SMTP system settings.'); }
            throw $e;
        }
        foreach ($values as $key => $value) { $this->modx->setOption($key, $value); }
        $this->modx->cacheManager->refresh(['system_settings' => []]);
    }

    public function profiles()
    {
        $hosts = trim((string) $this->read('mail_smtp_hosts', ''));
        $auth = ServerRepository::boolean($this->read('mail_smtp_auth', false));
        $user = (string) $this->read('mail_smtp_user', '');
        $password = (string) $this->read('mail_smtp_pass', '');
        $version = $this->modx->getVersionData();
        $secureKey = (int) $version['version'] >= 3 ? 'mail_smtp_secure' : 'mail_smtp_prefix';
        $secure = (string) $this->read($secureKey, '');
        $port = filter_var($this->read('mail_smtp_port', 25), FILTER_VALIDATE_INT);
        if ($hosts === '' || (strtolower($hosts) === 'localhost' && !$auth && $user === '' && $password === '' &&
            $port === 25 && $secure === '' &&
            !ServerRepository::boolean($this->read('mail_use_smtp', false)))) {
            throw new \InvalidArgumentException('В системных настройках MODX нет настроенного SMTP-подключения. Заполните SMTP хосты или добавьте сервер вручную.');
        }
        $timeout = filter_var($this->read('mail_smtp_timeout', 10), FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) { throw new \InvalidArgumentException('Проверьте системную настройку mail_smtp_port: порт должен быть от 1 до 65535.'); }
        if ($timeout === false || $timeout < 1 || $timeout > 60) { throw new \InvalidArgumentException('mail_smtp_timeout должен быть от 1 до 60 секунд для импорта в SMTPHub. Настройка не была изменена.'); }
        if (!in_array($secure, ['', 'ssl', 'tls'], true)) { throw new \InvalidArgumentException('Проверьте системную настройку ' . $secureKey . ': допустимы ssl, tls или пустое значение.'); }
        if ($auth && ($user === '' || $password === '')) { throw new \InvalidArgumentException('Для импорта SMTP с аутентификацией заполните mail_smtp_user и mail_smtp_pass.'); }
        $profiles = [];
        foreach (explode(';', $hosts) as $entry) {
            $entry = trim($entry);
            if ($entry === '') { continue; }
            if (!preg_match('~^(?:(ssl|tls)://)?([A-Za-z0-9.-]+)(?::([0-9]+))?$~D', $entry, $match)) {
                throw new \InvalidArgumentException('Не удалось импортировать SMTP хосты. Поддерживаются DNS-имена/IPv4, необязательный порт и префикс ssl:// или tls://. Разделитель серверов — точка с запятой. IPv6 пока не поддерживается.');
            }
            $profiles[] = ['id' => 'import_' . count($profiles), 'name' => 'SMTP MODX' . ($profiles ? ' ' . (count($profiles) + 1) : ''),
                'host' => $match[2], 'port' => isset($match[3]) ? (int) $match[3] : $port,
                'secure' => !empty($match[1]) ? $match[1] : $secure, 'auth' => $auth,
                'username' => $user, 'password' => $password, 'timeout' => $timeout,
                'auto_tls' => ServerRepository::boolean($this->read('mail_smtp_autotls', true))];
        }
        try { return Profiles::validate($profiles); }
        catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('SMTP-настройки MODX не подходят для импорта. Проверьте адреса, порты и данные аутентификации. Ни один сервер не импортирован.'); }
    }
}
