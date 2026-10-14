<?php
namespace SmtpHub;

class Profiles
{
    public static function load($file)
    {
        if (!is_string($file) || !is_file($file)) {
            throw new \RuntimeException('SMTPHub configuration file is unavailable.');
        }
        // Opening the file also works with Windows ACLs where is_readable()
        // can disagree with the actual read operation.
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            throw new \RuntimeException('SMTPHub configuration file is unavailable.');
        }
        fclose($handle);
        return self::validate(require $file);
    }

    public static function validate($items)
    {
        if (!is_array($items) || !$items || array_keys($items) !== range(0, count($items) - 1)) {
            throw new \InvalidArgumentException('SMTPHub requires a non-empty ordered list.');
        }
        $profiles = [];
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Invalid SMTPHub profile.');
            }
            if (isset($item['enabled']) && $item['enabled'] === false) {
                continue;
            }
            $p = array_merge([
                'port' => 587, 'secure' => 'tls', 'auth' => true,
                'username' => '', 'password' => '', 'auto_tls' => true, 'timeout' => 5,
                'sender_email' => '', 'sender_name' => '',
            ], $item);
            if (!isset($p['id'], $p['host']) || !is_string($p['id']) ||
                !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $p['id']) || isset($ids[$p['id']]) ||
                !is_string($p['host']) || !preg_match('/^[a-zA-Z0-9.-]+$/D', $p['host'])) {
                throw new \InvalidArgumentException('Invalid or duplicate SMTPHub id/host.');
            }
            if (!is_int($p['port']) || $p['port'] < 1 || $p['port'] > 65535 ||
                !in_array($p['secure'], ['', 'tls', 'ssl'], true) ||
                !is_int($p['timeout']) || $p['timeout'] < 1 || $p['timeout'] > 60 ||
                !is_bool($p['auth']) || !is_bool($p['auto_tls']) ||
                !is_string($p['username']) || !is_string($p['password'])) {
                throw new \InvalidArgumentException('Invalid SMTPHub connection settings.');
            }
            if (isset($p['password_env'])) {
                if (!is_string($p['password_env']) || $p['password_env'] === '') {
                    throw new \InvalidArgumentException('Invalid SMTPHub password environment variable.');
                }
                $password = getenv($p['password_env']);
                if ($password === false || $password === '') {
                    throw new \InvalidArgumentException('SMTPHub password environment variable is unavailable.');
                }
                $p['password'] = $password;
            }
            if ($p['auth'] && ($p['username'] === '' || $p['password'] === '')) {
                throw new \InvalidArgumentException('SMTPHub authentication requires credentials.');
            }
            if (!is_string($p['sender_email']) || !is_string($p['sender_name'])) {
                throw new \InvalidArgumentException('Invalid SMTPHub sender settings.');
            }
            $p['sender_email'] = trim($p['sender_email']);
            $p['sender_name'] = trim($p['sender_name']);
            if (strlen($p['sender_email']) > 254 || ($p['sender_email'] !== '' &&
                !filter_var($p['sender_email'], FILTER_VALIDATE_EMAIL)) ||
                mb_strlen($p['sender_name']) > 128 || preg_match('/[\x00-\x1f\x7f]/', $p['sender_name'])) {
                throw new \InvalidArgumentException('Invalid SMTPHub sender settings.');
            }
            $ids[$p['id']] = true;
            // A changed credential/endpoint must not inherit an old cooldown.
            $p['state_key'] = hash('sha256', serialize([
                $p['id'], $p['host'], $p['port'], $p['secure'], $p['auth'],
                $p['username'], $p['password'], $p['auto_tls'],
            ]));
            $profiles[] = $p;
        }
        if (!$profiles) {
            throw new \InvalidArgumentException('SMTPHub has no enabled servers.');
        }
        return $profiles;
    }
}
