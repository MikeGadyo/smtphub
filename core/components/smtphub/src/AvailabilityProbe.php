<?php
namespace SmtpHub;

/** A separate connection: never sends a message or touches failover state. */
class AvailabilityProbe
{
    public static function loadMailer($modx)
    {
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            if (class_exists('MODX\\Revolution\\Mail\\modPHPMailer')) {
                $class = 'MODX\\Revolution\\Mail\\modPHPMailer';
                new $class($modx);
            } else {
                $modx->getService('mail', 'mail.modPHPMailer');
            }
        }
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('Native PHPMailer unavailable.');
        }
    }

    public function check(array $profile)
    {
        $profile = Profiles::validate([$profile])[0];
        $mailer = $this->createMailer();
        $mailer->isSMTP();
        $mailer->Host = $profile['host'];
        $mailer->Port = $profile['port'];
        $mailer->SMTPSecure = $profile['secure'];
        $mailer->SMTPAutoTLS = $profile['auto_tls'];
        $mailer->SMTPAuth = $profile['auth'];
        $mailer->Username = $profile['username'];
        $mailer->Password = $profile['password'];
        $mailer->Timeout = $profile['timeout'];
        $mailer->getSMTPInstance()->Timelimit = $profile['timeout'];
        $mailer->SMTPDebug = 0;
        // PHPMailer can wait separately for greeting, TLS and authentication.
        // Avoid outputting a PHP execution-time error during a legitimate probe.
        @set_time_limit($profile['timeout'] * 8 + 15);
        try {
            $available = (bool) $mailer->smtpConnect();
        } catch (\Throwable $e) {
            $available = false;
        } finally {
            $mailer->smtpClose();
        }
        return ['available' => $available, 'checked_at' => gmdate('c'), 'message' => self::message($available)];
    }

    public static function message($available)
    {
        return $available
            ? 'SMTP-соединение установлено. Шифрование и авторизация проверены согласно настройкам. Письмо не отправлялось.'
            : 'Не удалось установить SMTP-соединение с указанными настройками. Проверьте адрес, порт, шифрование, логин и пароль.';
    }

    protected function createMailer()
    {
        return new \PHPMailer\PHPMailer\PHPMailer();
    }
}
