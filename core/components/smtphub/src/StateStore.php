<?php
namespace SmtpHub;

class StateStore
{
    private $directory;
    private $memory = [];
    private $warn;

    public function __construct($directory, callable $warn)
    {
        $this->directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
        $this->warn = $warn;
    }

    public function blockedUntil($key)
    {
        return $this->access($key, null);
    }

    public function write($key, $until)
    {
        $this->access($key, (int) $until);
    }

    private function access($key, $until)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid SMTPHub state key.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return $this->fallback($key, $until);
        }
        $handle = @fopen($this->directory . $key . '.php', 'c+');
        if (!$handle) {
            return $this->fallback($key, $until);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return $this->fallback($key, $until);
        }
        try {
            $content = stream_get_contents($handle);
            $stored = (int) substr($content, strlen("<?php exit; ?>\n"));
            if ($until !== null) {
                $content = "<?php exit; ?>\n" . $until;
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                    return $this->fallback($key, $until);
                }
                $stored = $until;
            }
            return $stored;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function fallback($key, $until)
    {
        ($this->warn)('state_unavailable');
        if ($until !== null) {
            $this->memory[$key] = $until;
        }
        return isset($this->memory[$key]) ? $this->memory[$key] : 0;
    }
}
