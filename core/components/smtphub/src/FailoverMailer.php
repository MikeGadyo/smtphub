<?php
namespace SmtpHub;

use PHPMailer\PHPMailer\PHPMailer;

class FailoverMailer extends PHPMailer
{
    private $hubRouter;
    private $hubTransactionConnection = false;

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
        // Parent smtpSend() checks the already selected connection. Once the
        // transaction starts it must never switch servers or replay a message.
        if ($this->hubTransactionConnection) {
            return $this->getSMTPInstance()->connected();
        }
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
        $originalSender = [$this->From, $this->FromName, $this->Sender];
        try {
            if (!$this->smtpConnect($this->SMTPOptions)) {
                throw new \PHPMailer\PHPMailer\Exception($this->lang('smtp_connect_failed'), self::STOP_CRITICAL);
            }
            $this->hubTransactionConnection = true;
            $profile = $this->hubRouter->selected();
            if ($profile['sender_email'] !== '' || $profile['sender_name'] !== '') {
                if ($profile['sender_email'] !== '') {
                    $this->From = $profile['sender_email'];
                    $this->Sender = $profile['sender_email'];
                }
                if ($profile['sender_name'] !== '') { $this->FromName = $profile['sender_name']; }
                // Initial preSend() validated the caller's message before any
                // SMTP attempt. Rebuild MIME and DKIM for the selected sender
                // before MAIL/RCPT/DATA; Reply-To and recipients stay intact.
                if (!parent::preSend()) { return false; }
                $header = $this->MIMEHeader;
                $body = $this->MIMEBody;
            }
            // A single SMTP transaction: never replay recipients or DATA.
            return parent::smtpSend($header, $body);
        } catch (\Exception $e) {
            $error = $this->getSMTPInstance()->getError();
            // QUIT replaces PHPMailer's last SMTP error. Capture the server
            // reply before cleanup so the native send() error remains useful.
            if ($e instanceof \PHPMailer\PHPMailer\Exception) {
                $this->setError($e->getMessage());
                $e = new \PHPMailer\PHPMailer\Exception($this->ErrorInfo, $e->getCode(), $e);
            }
            if ($e instanceof \PHPMailer\PHPMailer\Exception && $e->getCode() === self::STOP_CRITICAL &&
                empty($error['smtp_code']) && !$this->getSMTPInstance()->connected()) {
                $this->hubRouter->disconnected();
            }
            $this->smtpClose();
            throw $e;
        } finally {
            $this->hubTransactionConnection = false;
            [$this->From, $this->FromName, $this->Sender] = $originalSender;
            $this->smtpClose();
        }
    }
}
