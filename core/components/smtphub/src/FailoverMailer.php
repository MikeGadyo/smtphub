<?php
namespace SmtpHub;

use PHPMailer\PHPMailer\PHPMailer;

class FailoverMailer extends PHPMailer
{
    private $hubRouter;

    public static function upgrade(PHPMailer $original, Router $router)
    {
        $mailer = new self();
        // Preserve private/protected message state as well as public settings.
        // Only the exact native PHPMailer is accepted by Integration.
        $reflection = new \ReflectionObject($original);
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isStatic()) {
                if (PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                $property->setValue($mailer, $property->getValue($original));
            }
        }
        $mailer->hubRouter = $router;
        return $mailer;
    }

    public function preSend()
    {
        $this->isSMTP();
        return parent::preSend();
    }

    public function smtpConnect($options = null)
    {
        // Re-evaluate priority for each new message, including after reset().
        $this->smtpClose();
        return $this->hubRouter->connect(function (array $p) use ($options) {
            $this->Host = $p['host'];
            $this->Port = $p['port'];
            $this->SMTPSecure = $p['secure'];
            $this->SMTPAutoTLS = $p['auto_tls'];
            $this->SMTPAuth = $p['auth'];
            $this->Username = $p['username'];
            $this->Password = $p['password'];
            $this->AuthType = '';
            $this->Timeout = $p['timeout'];
            $this->getSMTPInstance()->Timelimit = $p['timeout'];
            $this->SMTPKeepAlive = false;
            try {
                return parent::smtpConnect($options);
            } finally {
                // PHPMailer closes failed connections itself. Exceptions must
                // also leave the next profile with a clean socket.
                if (!$this->getSMTPInstance()->connected()) {
                    $this->smtpClose();
                }
            }
        });
    }

    protected function smtpSend($header, $body)
    {
        try {
            // A single SMTP transaction: never replay recipients or DATA.
            return parent::smtpSend($header, $body);
        } catch (\Exception $e) {
            $error = $this->getSMTPInstance()->getError();
            if ($e instanceof \PHPMailer\PHPMailer\Exception && $e->getCode() === self::STOP_CRITICAL &&
                empty($error['smtp_code']) && !$this->getSMTPInstance()->connected()) {
                $this->hubRouter->disconnected();
            }
            $this->smtpClose();
            throw $e;
        }
    }
}
