(function () {
    'use strict';
    Ext.onReady(function () {
        var config = window.SMTPHubConfig;
        var labels = config.labels;
        var store = new Ext.data.JsonStore({
            idProperty: 'id', fields: ['id', 'name', 'priority', 'enabled', 'host', 'port', 'secure', 'auth', 'username', 'timeout', 'auto_tls', 'revision', 'has_password', 'availability']
        });
        var status = new Ext.BoxComponent({autoEl: {tag: 'div', cls: 'smtphub-status', html: 'Загрузка серверов…'}});
        var enabled = new Ext.form.Checkbox({boxLabel: 'Включить SMTPHub', name: 'enabled'});
        var cooldown = new Ext.form.NumberField({fieldLabel: 'Пауза после сбоя, сек.', name: 'cooldown', value: 60, minValue: 1, maxValue: 86400, allowDecimals: false, width: 110});
        var encode = Ext.util.Format.htmlEncode;
        var busy = false;
        var routingStatus = function () {
            return enabled.getValue() ? 'SMTPHub включён. Серверы используются сверху вниз.' : 'SMTPHub выключен. Для отправки используется штатная настройка MODX.';
        };
        var request = function (operation, values, done) {
            if (busy) { return; }
            busy = true;
            if (grid.rendered) { grid.getEl().mask(operation === 'list' ? 'Загрузка…' : 'Сохранение…'); }
            var finish = function () { busy = false; if (grid.rendered) { grid.getEl().unmask(); } };
            Ext.Ajax.request({
                url: config.connector, method: 'POST', headers: {modAuth: config.auth},
                params: Ext.apply({action: 'manage', operation: operation}, values || {}),
                success: function (response) {
                    finish();
                    var data;
                    try { data = Ext.decode(response.responseText); }
                    catch (error) { Ext.Msg.alert('SMTPHub', 'Сервер вернул некорректный ответ. Проверьте журнал MODX.'); return; }
                    if (!data.success) { Ext.Msg.alert('SMTPHub', encode(data.message || 'Не удалось сохранить изменения.')); return; }
                    store.loadData(data.object.servers);
                    enabled.setValue(data.object.enabled);
                    cooldown.setValue(data.object.cooldown);
                    if (status.rendered) { status.getEl().update(routingStatus()); }
                    if (done) { done(data.object); }
                    if (data.object.notice) { Ext.Msg.alert('SMTPHub', encode(data.object.notice)); }
                },
                failure: function () { finish(); Ext.Msg.alert('SMTPHub', 'Не удалось связаться с сервером. Проверьте соединение и вход в MODX.'); }
            });
        };
        var checkAvailability = function () {
            if (busy) { return; }
            busy = true;
            var pending = [], index = 0;
            store.each(function (row) {
                if (row.data.enabled) { pending.push(row); }
            });
            var next = function () {
                if (index >= pending.length) {
                    busy = false;
                    status.getEl().update(routingStatus() + (pending.length ? ' Проверка завершена. Наведите курсор на результат, чтобы увидеть подробности.' : ' Нет включённых серверов для проверки.'));
                    return;
                }
                var row = pending[index++], revision = row.data.revision;
                row.set('availability', {pending: true});
                row.commit();
                status.getEl().update('Проверка SMTP-серверов: ' + index + ' из ' + pending.length + '…');
                var accept = function (result) {
                    if (store.getById(row.id) === row && row.data.revision === revision) {
                        result.revision = revision;
                        row.set('availability', result);
                        row.commit();
                    }
                    next();
                };
                Ext.Ajax.request({
                    url: config.connector, method: 'POST', headers: {modAuth: config.auth},
                    timeout: (row.data.timeout * 8 + 30) * 1000,
                    params: {action: 'manage', operation: 'check', id: row.id, revision: revision},
                    success: function (response) {
                        var data;
                        try { data = Ext.decode(response.responseText); } catch (error) { data = null; }
                        if (data && data.success && String(data.object.id) === String(row.id) && data.object.revision === revision) {
                            accept(data.object.check);
                        } else {
                            accept({available: null, error: true, message: data && data.message ? data.message : 'Не удалось получить результат проверки. Обновите список.'});
                        }
                    },
                    failure: function () { accept({available: null, error: true, message: 'Не удалось получить результат проверки. Проверьте соединение и вход в MODX.'}); }
                });
            };
            next();
        };
        var selected = function () {
            var row = grid.getSelectionModel().getSelected();
            if (!row) { Ext.Msg.alert('SMTPHub', 'Сначала выберите сервер в списке.'); }
            return row;
        };
        var edit = function (record) {
            if (busy) { return; }
            var values = record ? record.data : {id: 0, revision: 0,
                enabled: true, auth: true, auto_tls: true, port: 587, secure: 'tls', timeout: 5};
            var secure = new Ext.form.ComboBox({fieldLabel: labels.secure, hiddenName: 'secure', name: 'secure_label',
                store: new Ext.data.SimpleStore({fields: ['value', 'label'], data: [['ssl', 'SSL (обычно 465)'], ['tls', 'TLS (STARTTLS, обычно 587)'], ['', 'Без шифрования']]}),
                valueField: 'value', displayField: 'label', mode: 'local', triggerAction: 'all', editable: false, forceSelection: true, anchor: '100%'});
            var form = new Ext.form.FormPanel({border: false, autoScroll: true, bodyStyle: 'padding:20px', labelWidth: 170,
                defaults: {anchor: '100%'}, items: [
                    {xtype: 'hidden', name: 'id'}, {xtype: 'hidden', name: 'revision'},
                    {xtype: 'textfield', fieldLabel: 'Название', name: 'name', allowBlank: false, maxLength: 128},
                    {xtype: 'textfield', fieldLabel: labels.host, name: 'host', allowBlank: false, maxLength: 255, emptyText: 'smtp.example.com'},
                    {xtype: 'numberfield', fieldLabel: labels.port, name: 'port', minValue: 1, maxValue: 65535, allowDecimals: false, allowBlank: false},
                    secure,
                    {xtype: 'checkbox', fieldLabel: labels.auth, boxLabel: 'Использовать логин и пароль', name: 'auth'},
                    {xtype: 'textfield', fieldLabel: labels.username, name: 'username', maxLength: 255},
                    {xtype: 'textfield', inputType: 'password', fieldLabel: labels.password, name: 'password', maxLength: 4096, autoCreate: {tag: 'input', type: 'password', autocomplete: 'new-password'}},
                    {xtype: 'box', autoEl: {tag: 'div', cls: 'smtphub-note', html: record ? 'Оставьте пароль пустым, чтобы сохранить прежний.' : 'Укажите пароль, если включена авторизация.'}},
                    {xtype: 'checkbox', fieldLabel: 'Очистить пароль', boxLabel: 'Удалить сохранённый пароль', name: 'clear_password', hidden: !record},
                    {xtype: 'numberfield', fieldLabel: labels.timeout + ', сек.', name: 'timeout', minValue: 1, maxValue: 60, allowDecimals: false, allowBlank: false},
                    {xtype: 'checkbox', fieldLabel: labels.auto_tls, boxLabel: 'Включать TLS, если сервер его поддерживает', name: 'auto_tls'},
                    {xtype: 'checkbox', fieldLabel: 'Состояние', boxLabel: 'Сервер включён', name: 'enabled'}
                ]});
            var size = Ext.getBody().getViewSize();
            var window = new Ext.Window({title: record ? 'Изменить SMTP-сервер' : 'Добавить SMTP-сервер', cls: 'smtphub-window',
                width: Math.min(540, size.width - 24), height: Math.min(720, size.height - 32), modal: true, resizable: false, draggable: false, layout: 'fit', items: [form],
                buttons: [{text: 'Сохранить', handler: function () {
                    if (!form.getForm().isValid()) { return; }
                    var params = form.getForm().getValues();
                    ['auth', 'enabled', 'auto_tls', 'clear_password'].forEach(function (key) {
                        var field = form.getForm().findField(key); params[key] = field.getValue() ? 1 : 0;
                    });
                    request('save', params, function () { window.close(); });
                }}, {text: 'Отмена', handler: function () { window.close(); }}]
            });
            window.show(); form.getForm().setValues(values); secure.setValue(values.secure);
            form.getForm().findField('name').focus(false, 100);
        };
        var remove = function (row) {
            Ext.Msg.confirm('Удалить SMTP-сервер', 'Удалить «' + encode(row.data.name) + '» из списка?', function (button) {
                if (button === 'yes') { request('remove', {id: row.id, revision: row.data.revision}); }
            });
        };
        var menuRow;
        var rowMenu = new Ext.menu.Menu({items: [
            {text: 'Редактировать', handler: function () { edit(menuRow); }},
            {text: 'Сделать копию', handler: function () { request('duplicate', {id: menuRow.id, revision: menuRow.data.revision}); }},
            {text: 'Использовать в MODX', handler: function () {
                var row = menuRow;
                if (busy) { return; }
                Ext.Msg.confirm('Использовать в MODX', 'Скопировать настройки «' + encode(row.data.name) + '» в системные настройки MODX? Текущие SMTP-настройки будут заменены, штатная SMTP-отправка будет включена. Состояние SMTPHub не изменится.', function (button) {
                    if (button === 'yes') { request('use_native', {id: row.id, revision: row.data.revision}); }
                });
            }},
            {text: 'Удалить', handler: function () { remove(menuRow); }}
        ]});
        var reorder = function (from, to) {
            if (busy || from === to || from < 0 || to < 0 || to >= store.getCount()) { return; }
            var items = [];
            store.each(function (row) { items.push({id: row.id, revision: row.data.revision}); });
            items.splice(to, 0, items.splice(from, 1)[0]);
            var movedId = items[to].id;
            request('reorder', {items: Ext.encode(items)}, function () {
                var row = grid.getView().getRow(store.indexOfId(movedId));
                if (row) { var handle = row.querySelector('.smtphub-drag-handle'); if (handle) { handle.focus(); } }
            });
        };
        var installDragging = function () {
            var view = grid.getView(), body = view.mainBody.dom;
            var draggedId = null, markedRow = null, dropIndex = null, dragAllowed = false;
            var clearMark = function () {
                if (markedRow) { Ext.fly(markedRow).removeClass(['smtphub-drop-before', 'smtphub-drop-after']); }
                markedRow = null; dropIndex = null;
            };
            var decorate = function () {
                Ext.each(view.getRows(), function (row) {
                    row.setAttribute('draggable', 'true');
                    var handle = row.querySelector('.smtphub-drag-handle');
                    if (handle) { handle.setAttribute('draggable', 'true'); }
                });
            };
            view.on('refresh', decorate); view.on('rowupdated', decorate); decorate();
            // Select the row without ExtJS cancelling the native drag gesture.
            // Action buttons keep their ordinary click behavior.
            body.addEventListener('mousedown', function (event) {
                var button = event.target.closest('button'), index = view.findRowIndex(event.target);
                dragAllowed = event.button === 0 && !busy && index !== false && index >= 0 &&
                    (!button || Ext.fly(button).hasClass('smtphub-drag-handle'));
                if (dragAllowed) { grid.getSelectionModel().selectRow(index); event.stopPropagation(); }
            }, true);
            body.addEventListener('dragstart', function (event) {
                var index = view.findRowIndex(event.target);
                if (busy || !dragAllowed || index === false || index < 0) { event.preventDefault(); return; }
                draggedId = store.getAt(index).id;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(draggedId));
            });
            body.addEventListener('dragover', function (event) {
                if (draggedId === null || busy) { return; }
                var index = view.findRowIndex(event.target);
                if (index === false || index < 0) { return; }
                event.preventDefault(); event.dataTransfer.dropEffect = 'move';
                clearMark();
                markedRow = view.getRow(index);
                var bounds = markedRow.getBoundingClientRect();
                var after = event.clientY >= bounds.top + bounds.height / 2;
                dropIndex = index + (after ? 1 : 0);
                Ext.fly(markedRow).addClass(after ? 'smtphub-drop-after' : 'smtphub-drop-before');
            });
            body.addEventListener('drop', function (event) {
                if (draggedId === null || dropIndex === null) { return; }
                event.preventDefault();
                var from = store.indexOfId(draggedId), to = dropIndex;
                if (to > from) { to--; }
                clearMark(); draggedId = null;
                reorder(from, to);
            });
            body.addEventListener('dragend', function () { clearMark(); draggedId = null; dragAllowed = false; });
            body.addEventListener('dragleave', function (event) { if (!body.contains(event.relatedTarget)) { clearMark(); } });
            body.addEventListener('keydown', function (event) {
                if (!Ext.fly(event.target).hasClass('smtphub-drag-handle')) { return; }
                if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') { return; }
                event.preventDefault(); event.stopPropagation();
                var index = view.findRowIndex(event.target);
                reorder(index, index + (event.key === 'ArrowUp' ? -1 : 1));
            });
            grid.on('beforedestroy', function () { rowMenu.destroy(); });
        };
        var fitColumns = function (panel, width) {
            var columns = panel.getColumnModel(), hide = width < 1000;
            Ext.each(['username', 'has_password'], function (id) {
                var index = columns.getIndexById(id);
                if (columns.isHidden(index) !== hide) { columns.setHidden(index, hide); }
            });
        };
        var textCell = function (value, meta) {
            meta.attr = 'ext:qtip="' + encode(value) + '"';
            return encode(value);
        };
        var grid = new Ext.grid.GridPanel({store: store, border: true, autoHeight: true, stripeRows: true, enableColumnMove: false,
            sm: new Ext.grid.RowSelectionModel({singleSelect: true}), viewConfig: {forceFit: true, emptyText: 'Серверов пока нет. Нажмите «Добавить SMTP сервер».', deferEmptyText: false},
            colModel: new Ext.grid.ColumnModel({defaults: {sortable: false, menuDisabled: true}, columns: [
                {header: '', width: 32, fixed: true, menuDisabled: true, renderer: function (value, meta, row) {
                    return '<button type="button" class="smtphub-drag-handle" aria-label="Переместить ' + encode(row.data.name) + '" title="Перетащите строку. На клавиатуре: стрелки вверх и вниз.">⠿</button>';
                }},
                {header: 'Название', dataIndex: 'name', width: 160, renderer: textCell},
                {header: labels.host, dataIndex: 'host', width: 180, renderer: textCell},
                {header: 'Порт', dataIndex: 'port', width: 55, fixed: true},
                {header: 'Шифрование', dataIndex: 'secure', width: 105, fixed: true, renderer: function (value) { return value === 'tls' ? 'TLS (STARTTLS)' : value === 'ssl' ? 'SSL' : 'Нет'; }},
                {id: 'username', header: labels.username, dataIndex: 'username', width: 150, renderer: textCell},
                {id: 'has_password', header: labels.password, dataIndex: 'has_password', width: 80, renderer: function (value) { return value ? 'Задан' : 'Не задан'; }},
                {header: 'Доступен', dataIndex: 'availability', width: 146, fixed: true, renderer: function (value, meta, row) {
                    var text = 'Не проверялся', color = 'unchecked';
                    var tip = row.data.enabled ? 'Нажмите «Обновить», чтобы проверить SMTP-соединение. Письмо отправляться не будет.' : 'Сервер выключен. Проверка не выполнялась.';
                    if (value) {
                        if (value.pending) { text = 'Проверяется…'; color = 'pending'; }
                        else if (value.error) { text = 'Ошибка проверки'; color = 'unchecked'; }
                        else if (value.available === true) { text = 'Да'; color = 'yes'; }
                        else if (value.available === false) { text = 'Нет'; color = 'no'; }
                        tip = value.message || 'Проверка SMTP-соединения…';
                        if (value.checked_at) {
                            var date = new Date(value.checked_at);
                            if (!isNaN(date.getTime())) {
                                var pad = function (number) { return number < 10 ? '0' + number : String(number); };
                                text += ' (' + pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + pad(date.getFullYear() % 100) + ')';
                                tip += ' Время проверки: ' + date.toLocaleString();
                            }
                        }
                        if (value.stale) { text += ' *'; tip += ' Настройки подключения изменены после этой проверки. Нажмите «Обновить» для актуального результата.'; }
                        if (!row.data.enabled && !value.pending) { tip += ' Сейчас сервер выключен; показан сохранённый результат предыдущей проверки.'; }
                    }
                    meta.attr = 'ext:qtip="' + encode(tip) + '"';
                    return '<span class="smtphub-availability smtphub-availability-' + color + '" title="' + encode(tip) + '">' + text + '</span>';
                }},
                {header: 'Действия', width: 96, fixed: true, menuDisabled: true, renderer: function (value, meta, row) {
                    var on = row.data.enabled, name = encode(row.data.name);
                    return '<button type="button" class="smtphub-row-action smtphub-power ' + (on ? 'smtphub-on' : 'smtphub-off') + '" data-action="toggle" aria-pressed="' + on + '" aria-label="' + (on ? 'Выключить ' : 'Включить ') + name + '" title="' + (on ? 'Включён. Нажмите, чтобы выключить' : 'Выключен. Нажмите, чтобы включить') + '"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v8M6.5 5.7a9 9 0 1 0 11 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>' +
                        '<button type="button" class="smtphub-row-action smtphub-menu" data-action="menu" aria-haspopup="menu" aria-label="Действия: ' + name + '" title="Действия"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><g fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></g></svg></button>';
                }}
            ]}),
            tbar: [
                {text: 'Добавить SMTP сервер', handler: function () { edit(null); }},
                {text: 'Импорт настроек SMTP', tooltip: 'Скопировать системные SMTP-настройки MODX в начало списка.', handler: function () { request('import'); }},
                '->', {text: 'Обновить', tooltip: 'Обновить список и проверить соединение с включёнными SMTP-серверами без отправки письма.', handler: function () { request('list', {}, checkAvailability); }}
            ], listeners: {
                afterrender: function (panel) { installDragging(); fitColumns(panel, panel.getWidth()); },
                resize: fitColumns,
                cellclick: function (panel, index, column, event) {
                    var button = event.getTarget('.smtphub-row-action');
                    if (!button || busy) { return; }
                    var row = store.getAt(index);
                    if (button.getAttribute('data-action') === 'toggle') {
                        request('toggle', {id: row.id, revision: row.data.revision, enabled: row.data.enabled ? 0 : 1});
                    } else {
                        menuRow = row;
                        var bounds = button.getBoundingClientRect();
                        rowMenu.showAt([bounds.left + window.pageXOffset, bounds.bottom + window.pageYOffset + 6]);
                    }
                },
                rowdblclick: function (panel, index, event) {
                    if (!event.getTarget('button')) { var row = selected(); if (row) { edit(row); } }
                }
            }
        });
        MODx.add({xtype: 'panel', id: 'smtphub-panel', border: false, autoHeight: true, items: [
            {xtype: 'box', autoEl: {tag: 'div', cls: 'smtphub-intro', html: '<h1 class="smtphub-title">SMTPHub</h1><p>Серверы используются сверху вниз. Перетаскивайте строки за любое место, чтобы изменить порядок. Новый сервер добавляется в конец списка.</p>'}},
            {xtype: 'form', cls: 'smtphub-settings', border: false, labelWidth: 170, autoHeight: true, items: [enabled, cooldown],
                buttons: [{text: 'Сохранить настройки', handler: function () { if (cooldown.isValid()) { request('settings', {enabled: enabled.getValue() ? 1 : 0, cooldown: cooldown.getValue()}); } }}]},
            grid, status,
            {xtype: 'box', autoEl: {tag: 'div', cls: 'smtphub-note', html: 'SMTPHub ' + encode(config.version) + ' · Автор: ' + encode(config.author)}}
        ]});
        request('list');
    });
}());
