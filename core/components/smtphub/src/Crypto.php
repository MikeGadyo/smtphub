<?php
namespace SmtpHub;

class Crypto
{
    private $file;
    public function __construct($modx) { $this->file = $modx->getOption('core_path') . 'config/smtphub.key.php'; }

    public function initialize()
    {
        if (!is_file($this->file)) {
            $handle = @fopen($this->file, 'x');
            if (!$handle) { throw new \RuntimeException('Cannot create SMTPHub encryption key.'); }
            try {
                $content = '<?php return ' . var_export(base64_encode(random_bytes(32)), true) . ';';
                if (fwrite($handle, $content) !== strlen($content)) { throw new \RuntimeException('Cannot write SMTPHub encryption key.'); }
            } finally { fclose($handle); }
            @chmod($this->file, 0600);
        }
        $this->key();
    }

    private function key()
    {
        if (!is_file($this->file)) { throw new \RuntimeException('SMTPHub encryption key is missing.'); }
        $key = base64_decode(require $this->file, true);
        if (!is_string($key) || strlen($key) !== 32) { throw new \RuntimeException('Invalid SMTPHub encryption key.'); }
        return $key;
    }

    public function encrypt($password)
    {
        if ($password === '') { return ''; }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($password, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, 'smtphub-v1');
        if ($cipher === false) { throw new \RuntimeException('Cannot encrypt SMTPHub password.'); }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt($encoded)
    {
        if ($encoded === '') { return ''; }
        $data = substr($encoded, 0, 3) === 'v1:' ? base64_decode(substr($encoded, 3), true) : false;
        if ($data === false || strlen($data) < 28) { throw new \RuntimeException('Invalid SMTPHub password ciphertext.'); }
        $password = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA,
            substr($data, 0, 12), substr($data, 12, 16), 'smtphub-v1');
        if ($password === false) { throw new \RuntimeException('Cannot decrypt SMTPHub password.'); }
        return $password;
    }
}
