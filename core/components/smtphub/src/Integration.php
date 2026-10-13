<?php
namespace SmtpHub;

class Integration
{
    public static function install($modx, ServerRepository $repository = null)
    {
        if (!$modx->getOption('smtphub.enabled', null, false)) {
            return false;
        }
        $log = function ($event, $id = null) use ($modx) {
            $modx->log(1, '[SMTPHub] ' . $event . ($id === null ? '' : ' [' . $id . ']'));
        };
        $modern = class_exists('MODX\\Revolution\\Mail\\modPHPMailer');
        if ($modern) {
            $class = 'MODX\\Revolution\\Mail\\modPHPMailer';
            if (!$modx->services->has('mail')) {
                $modx->services->add('mail', new $class($modx));
            }
            $mail = $modx->services->get('mail');
            // An existing custom service owns its own routing.
            if (get_class($mail) !== $class) {
                $log('custom_mail_service_skipped');
                return false;
            }
            if (isset($modx->mail) && $modx->mail !== $mail) {
                $log('conflicting_mail_service_skipped');
                return false;
            }
        } else {
            $mail = $modx->getService('mail', 'mail.modPHPMailer');
            if (!$mail || get_class($mail) !== 'modPHPMailer') {
                $log('custom_mail_service_skipped');
                return false;
            }
        }
        if ($mail->mailer instanceof FailoverMailer) {
            return true;
        }
        if (get_class($mail->mailer) !== 'PHPMailer\\PHPMailer\\PHPMailer') {
            $log('custom_phpmailer_skipped');
            return false;
        }
        // Keep initialization safe for the website; reject mail when enabled
        // but misconfigured, instead of accidentally using mail()/native SMTP.
        try {
            $profiles = ($repository ?: new ServerRepository($modx))->profiles();
        } catch (\Throwable $e) {
            $log('configuration_invalid');
            $profiles = [];
        }
        $state = new StateStore($modx->getOption('core_path') . 'cache/smtphub/', function ($event) use ($log) {
            $log($event);
        });
        $router = new Router($profiles, $state, $modx->getOption('smtphub.cooldown', null, 60), $log);
        $mail->mailer = FailoverMailer::upgrade($mail->mailer, $router);
        if ($modern) {
            $modx->mail = $mail;
        }
        return true;
    }
}
