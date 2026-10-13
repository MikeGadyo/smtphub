<?php
require_once dirname(__DIR__) . '/autoload.php';
if (!class_exists('SmtpHubProcessorBase', false)) {
    class_alias(class_exists('MODX\\Revolution\\Processors\\Processor') ? 'MODX\\Revolution\\Processors\\Processor' : 'modProcessor', 'SmtpHubProcessorBase');
}
class SmtpHubManageProcessor extends SmtpHubProcessorBase
{
    public function checkPermissions()
    {
        return $this->modx->user && $this->modx->user->hasSessionContext('mgr') && $this->modx->hasPermission('settings');
    }

    public function process()
    {
        $repository = new \SmtpHub\ServerRepository($this->modx);
        $notice = '';
        try {
            switch ($this->getProperty('operation', 'list')) {
                case 'list': break;
                case 'check':
                    $id = $this->getProperty('id');
                    $revision = $this->getProperty('revision');
                    $profile = $repository->profileForCheck($id, $revision);
                    $result = ['available' => null, 'checked_at' => null, 'message' => 'Сервер выключен. Проверка не выполнялась.'];
                    if ($profile !== null) {
                        \SmtpHub\AvailabilityProbe::loadMailer($this->modx);
                        $result = (new \SmtpHub\AvailabilityProbe())->check($profile);
                        $repository->saveAvailability($id, $revision, $result);
                    }
                    return $this->success('', ['id' => (int) $id, 'revision' => (int) $revision, 'check' => $result]);
                case 'use_native':
                    $profile = $repository->profileForUse($this->getProperty('id'), $this->getProperty('revision'));
                    (new \SmtpHub\NativeSettings($this->modx))->apply($profile);
                    $notice = 'Настройки сервера скопированы в системные настройки MODX. Штатная SMTP-отправка включена. Состояние SMTPHub не изменено: пока он включён, отправка идёт через его список. Для штатной отправки выключите SMTPHub.';
                    break;
                case 'save': $repository->save($this->getProperties()); break;
                case 'duplicate': $repository->duplicate($this->getProperty('id'), $this->getProperty('revision')); break;
                case 'import':
                    $result = $repository->import((new \SmtpHub\NativeSettings($this->modx))->profiles());
                    $notice = 'Импорт завершён. Добавлено: ' . $result['added'] . '. Уже существовали: ' . $result['reused'] . '. Подключения MODX включены и помещены в начало списка. Состояние самого SMTPHub не изменено.';
                    break;
                case 'remove': $repository->remove($this->getProperty('id'), $this->getProperty('revision')); break;
                case 'move': $repository->move($this->getProperty('id'), $this->getProperty('direction')); break;
                case 'toggle': $repository->toggle($this->getProperty('id'), $this->getProperty('revision'), $this->getProperty('enabled')); break;
                case 'reorder':
                    $items = json_decode((string) $this->getProperty('items'), true);
                    if (!is_array($items)) { throw new \InvalidArgumentException('Некорректный порядок серверов.'); }
                    $repository->reorder($items);
                    break;
                case 'settings':
                    $cooldown = filter_var($this->getProperty('cooldown'), FILTER_VALIDATE_INT);
                    if ($cooldown === false || $cooldown < 1 || $cooldown > 86400) { throw new \InvalidArgumentException('Пауза должна быть от 1 до 86400 секунд.'); }
                    $enabled = \SmtpHub\ServerRepository::boolean($this->getProperty('enabled'));
                    if ($enabled) {
                        $active = array_filter($repository->listing(), function ($row) { return $row['enabled']; });
                        if (!$active) { throw new \InvalidArgumentException('Сначала добавьте хотя бы один включённый SMTP-сервер.'); }
                        $repository->profiles();
                    }
                    foreach (['enabled' => $enabled ? '1' : '0', 'cooldown' => (string) $cooldown] as $key => $value) {
                        $class = class_exists('MODX\\Revolution\\modSystemSetting') ? 'MODX\\Revolution\\modSystemSetting' : 'modSystemSetting';
                        $setting = $this->modx->getObject($class, 'smtphub.' . $key);
                        if (!$setting) { throw new \RuntimeException('SMTPHub setting missing.'); }
                        $setting->set('value', $value);
                        if (!$setting->save()) { throw new \RuntimeException('Cannot save SMTPHub setting.'); }
                        $this->modx->setOption('smtphub.' . $key, $value);
                    }
                    $this->modx->cacheManager->refresh();
                    break;
                default: return $this->failure('Неизвестное действие.');
            }
            return $this->success('', ['servers' => $repository->listing(),
                'enabled' => (bool) $this->modx->getOption('smtphub.enabled', null, false),
                'notice' => $notice,
                'cooldown' => (int) $this->modx->getOption('smtphub.cooldown', null, 60)]);
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage());
        } catch (\Throwable $e) {
            $this->modx->log(1, '[SMTPHub] manager_operation_failed');
            return $this->failure('Не удалось выполнить действие. Проверьте установку SMTPHub и журнал MODX.');
        }
    }
}
return 'SmtpHubManageProcessor';
