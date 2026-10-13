<?php
namespace SmtpHub;

class Router
{
    private $profiles;
    private $state;
    private $cooldown;
    private $log;
    private $clock;
    private $selected;

    public function __construct(array $profiles, StateStore $state, $cooldown, callable $log, callable $clock = null)
    {
        $this->profiles = $profiles;
        $this->state = $state;
        $this->cooldown = max(1, min(86400, (int) $cooldown));
        $this->log = $log;
        $this->clock = $clock ?: 'time';
    }

    public function connect(callable $attempt)
    {
        $this->selected = null;
        foreach ($this->profiles as $p) {
            if ($this->state->blockedUntil($p['state_key']) > ($this->clock)()) {
                continue;
            }
            try {
                $connected = $attempt($p);
            } catch (\Exception $e) {
                $connected = false;
            }
            if ($connected) {
                $this->selected = $p;
                $this->state->write($p['state_key'], 0);
                return true;
            }
            $this->fail($p);
        }
        ($this->log)('all_unavailable', null);
        return false;
    }

    public function disconnected()
    {
        if ($this->selected !== null) {
            $this->fail($this->selected);
            $this->selected = null;
        }
    }

    private function fail(array $p)
    {
        $this->state->write($p['state_key'], ($this->clock)() + $this->cooldown);
        ($this->log)('server_unavailable', $p['id']);
    }
}
