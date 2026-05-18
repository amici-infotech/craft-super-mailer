(function() {
    var dataElement = document.getElementById('superMailerEventPickerData');
    var search = document.getElementById('eventSearch');
    var hidden = document.getElementById('eventValue');
    var results = document.getElementById('eventAutocompleteResults');
    var generator = document.getElementById('eventCodeGenerator');

    if (!dataElement || !search || !hidden || !results || !generator) {
        return;
    }

    var titleInput = document.getElementById('title');
    var handleInput = document.getElementById('handle');
    if (titleInput && handleInput && handleInput.value === '' && window.Craft && Craft.SlugGenerator) {
        new Craft.SlugGenerator('#title', '#handle');
    }

    var data = {};
    try {
        data = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        data = {};
    }

    var events = Array.isArray(data.events) ? data.events : [];
    var currentValue = data.currentValue || '';
    var noMatchesLabel = data.noMatchesLabel || 'No matching events found.';
    var copiedLabel = data.copiedLabel || 'Copied';
    var conditionFieldOptions = Array.isArray(data.conditionFieldOptions) ? data.conditionFieldOptions : [];
    var conditionSuggestions = data.conditionSuggestions || {};
    var selectedEvent = generator.querySelector('.super-mailer-selected-event');
    var code = document.getElementById('eventExampleCode');
    var copyButton = document.getElementById('copyEventCode');
    var variables = document.getElementById('eventVariableList');
    var conditionTable = document.getElementById('conditionRulesTable');
    var addConditionButton = document.getElementById('addConditionRule');
    var conditionMatchMode = document.getElementById('conditionMatchMode');
    var phpCondition = document.getElementById('phpCondition');
    var baseCode = '';
    var currentCode = '';

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function highlightPhp(source) {
        var pattern = /(\/\/[^\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\$[a-zA-Z_][a-zA-Z0-9_]*|\b(?:use|function|class|return|if|else|new|true|false|null)\b|\bEVENT_[A-Z0-9_]+\b|\b[A-Z][a-zA-Z0-9_\\]*(?=::class|::EVENT_| \$event|;)|\bon(?=\()|===|==|&&|\|\||!|::|=>|[()[\]{};,])/g;
        var html = '';
        var lastIndex = 0;
        var match;

        while ((match = pattern.exec(source)) !== null) {
            var token = match[0];
            html += escapeHtml(source.slice(lastIndex, match.index));
            html += '<span class="' + tokenClass(token) + '">' + escapeHtml(token) + '</span>';
            lastIndex = pattern.lastIndex;
        }

        html += escapeHtml(source.slice(lastIndex));
        return html;
    }

    function tokenClass(token) {
        if (token.indexOf('//') === 0) {
            return 'super-mailer-token-comment';
        }

        if (token.indexOf('"') === 0 || token.indexOf("'") === 0) {
            return 'super-mailer-token-string';
        }

        if (token.indexOf('$') === 0) {
            return 'super-mailer-token-variable';
        }

        if (/^EVENT_/.test(token)) {
            return 'super-mailer-token-constant';
        }

        if (/^(use|function|class|return|if|else|new|true|false|null)$/.test(token)) {
            return 'super-mailer-token-keyword';
        }

        if (token === 'on') {
            return 'super-mailer-token-function';
        }

        if (/^[A-Z]/.test(token)) {
            return 'super-mailer-token-class';
        }

        return 'super-mailer-token-punctuation';
    }

    function searchableText(event) {
        return [
            event.label,
            event.class,
            event.constant,
            event.eventName,
            event.eventType
        ].join(' ').toLowerCase();
    }

    function renderResults(matches) {
        if (!matches.length) {
            results.innerHTML = '<div class="zilch smalltext">' + escapeHtml(noMatchesLabel) + '</div>';
            results.classList.remove('hidden');
            return;
        }

        results.innerHTML = matches.slice(0, 20).map(function(event) {
            return '<button type="button" class="super-mailer-event-option" data-value="' + escapeHtml(event.value) + '">' +
                '<strong>' + escapeHtml(event.class + '::' + event.constant) + '</strong>' +
                '<code>' + escapeHtml(event.eventName + ' | ' + event.eventType) + '</code>' +
                '</button>';
        }).join('');
        results.classList.remove('hidden');
    }

    function renderEvent(event) {
        if (!event) {
            generator.classList.add('hidden');
            return;
        }

        search.value = event.class + '::' + event.constant;
        hidden.value = event.value;
        selectedEvent.innerHTML = '<strong>' + escapeHtml(event.class + '::' + event.constant) + '</strong>' +
            '<code>' + escapeHtml(event.eventName + ' | ' + event.eventType) + '</code>';
        baseCode = event.code || '';
        updateCodePreview();
        variables.innerHTML = '<ul class="super-mailer-event-variables">' + (event.variables || []).map(function(variable) {
            return '<li><code>' + escapeHtml(variable.name) + '</code>' +
                ' <span class="super-mailer-variable-type">' + escapeHtml(variable.type || 'mixed') + '</span>' +
                (variable.description ? '<div class="smalltext">' + escapeHtml(variable.description) + '</div>' : '') +
                '</li>';
        }).join('') + '</ul>';
        generator.classList.remove('hidden');
    }

    function findByValue(value) {
        return events.find(function(event) {
            return event.value === value;
        }) || null;
    }

    function conditionLabel(field) {
        var option = conditionFieldOptions.find(function(item) {
            return item.value === field;
        });

        return option ? option.label : field;
    }

    function collectConditionRules() {
        if (!conditionTable) {
            return [];
        }

        return Array.from(conditionTable.querySelectorAll('[data-condition-row]')).map(function(row) {
            return {
                field: row.querySelector('[name$="[field]"]') ? row.querySelector('[name$="[field]"]').value : '',
                operator: row.querySelector('[name$="[operator]"]') ? row.querySelector('[name$="[operator]"]').value : 'equals',
                value: row.querySelector('[name$="[value]"]') ? row.querySelector('[name$="[value]"]').value : ''
            };
        }).filter(function(rule) {
            return rule.field || rule.value;
        });
    }

    function conditionsPreviewCode() {
        var lines = [];
        var rules = collectConditionRules();
        var customPhp = phpCondition ? phpCondition.value.trim() : '';
        var tableMatchMode = conditionMatchMode && conditionMatchMode.value === 'any' ? 'any' : 'all';
        var conditionLines = [];

        if (rules.length) {
            lines.push('        // Super Mailer condition table (' + tableMatchMode + '):');
            conditionLines = tableConditionLines(rules, tableMatchMode, customPhp !== '');
        }

        if (customPhp) {
            lines.push('        // Super Mailer custom PHP condition:');
            if (conditionLines.length) {
                conditionLines[conditionLines.length - 1] += ' &&';
            }
            conditionLines.push(customPhp);
        }

        if (!conditionLines.length) {
            return '';
        }

        if (lines.length) {
            lines.push('        if (');
            conditionLines.forEach(function(conditionLine, index) {
                lines.push('            ' + conditionLine);
            });
            lines.push('        ) {');
            lines.push('            // ...');
            lines.push('        }');
            lines.push('');
        }

        return lines.join('\n');
    }

    function escapePhpSingleQuotedString(value) {
        return String(value || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    function tableConditionLines(rules, matchMode, hasCustomTwig) {
        var joiner = matchMode === 'any' ? ' ||' : ' &&';
        var expressions = rules.map(conditionExpression);

        if (!hasCustomTwig || expressions.length === 1) {
            return expressions.map(function(expression, index) {
                return expression + (index < expressions.length - 1 ? joiner : '');
            });
        }

        var lines = ['('];
        expressions.forEach(function(expression, index) {
            lines.push('    ' + expression + (index < expressions.length - 1 ? joiner : ''));
        });
        lines.push(')');

        return lines;
    }

    function conditionExpression(rule) {
        var value = escapePhpSingleQuotedString(rule.value || '');
        var fieldExpression = {
            'element.status': '($event->sender->status ?? null)',
            'entry.type.handle': '($event->sender->type->handle ?? null)',
            'entry.section.handle': '($event->sender->section->handle ?? null)',
            'element.siteId': '((string)($event->sender->siteId ?? \'\'))',
            'entry.authorId': '((string)($event->sender->authorId ?? \'\'))'
        }[rule.field] || 'null';

        if (rule.field === 'element.status') {
            return fieldExpression + " == '" + (value || 'enabled') + "'";
        }

        if (rule.operator === 'contains') {
            return 'in_array(' + fieldExpression + ', ' + phpArray(parseTokenValues(rule.value)) + ', true)';
        }

        return fieldExpression + " == '" + value + "'";
    }

    function phpArray(values) {
        return '[' + values.map(function(value) {
            return "'" + escapePhpSingleQuotedString(value) + "'";
        }).join(', ') + ']';
    }

    function updateCodePreview() {
        var previewCode = conditionsPreviewCode();
        currentCode = previewCode ? baseCode.replace('        // ...', previewCode) : baseCode;
        code.innerHTML = highlightPhp(currentCode);
    }

    function updateConditionIndexes() {
        if (!conditionTable) {
            return;
        }

        Array.from(conditionTable.querySelectorAll('[data-condition-row]')).forEach(function(row, index) {
            row.querySelectorAll('[name]').forEach(function(input) {
                input.name = input.name.replace(/conditionRules\[\d+]/, 'conditionRules[' + index + ']');
            });
        });
    }

    function conditionValueType(field) {
        if (field === 'element.status') {
            return 'toggle';
        }

        if (field === 'entry.authorId') {
            return 'author';
        }

        if (conditionSuggestions[field]) {
            return 'selectize';
        }

        return 'text';
    }

    function renderTextValue(name, value, field) {
        var placeholder = {
            'entry.type.handle': 'article',
            'entry.section.handle': 'blog',
            'element.siteId': '1'
        }[field] || '';

        return '<input type="text" name="' + escapeHtml(name) + '" value="' + escapeHtml(value) + '" class="text fullwidth super-mailer-condition-text" placeholder="' + escapeHtml(placeholder) + '">';
    }

    function parseTokenValues(value) {
        return String(value || '').split(',').map(function(item) {
            return item.trim();
        }).filter(Boolean);
    }

    function renderAuthorToken(value, label) {
        return '<span class="super-mailer-token" data-token-value="' + escapeHtml(value) + '">' +
            '<span>' + escapeHtml(label || value) + '</span>' +
            '<button type="button" data-remove-token aria-label="Remove ' + escapeHtml(label || value) + '"></button>' +
            '</span>';
    }

    function renderSelectizeValue(name, value, field) {
        var values = parseTokenValues(value);
        var options = conditionSuggestions[field] || [];

        return '<div class="super-mailer-condition-selectize-wrap" data-condition-selectize data-field="' + escapeHtml(field) + '">' +
            '<input type="hidden" name="' + escapeHtml(name) + '" value="' + escapeHtml(values.join(', ')) + '">' +
            '<select multiple class="selectize fullwidth" data-condition-selectize-input>' +
            options.map(function(option) {
                var selected = values.indexOf(String(option.value)) !== -1 ? ' selected' : '';
                return '<option value="' + escapeHtml(option.value) + '"' + selected + '>' + escapeHtml(option.label || option.value) + '</option>';
            }).join('') +
            '</select>' +
            '</div>';
    }

    function initConditionSelectize(row) {
        if (!window.jQuery || !jQuery.fn.selectize) {
            return;
        }

        var $select = jQuery(row).find('[data-condition-selectize-input]');
        if (!$select.length || $select.data('selectize')) {
            return;
        }

        $select.selectize({
            plugins: ['remove_button', 'selectize-plugin-a11y'],
            dropdownParent: 'body',
            searchField: ['text', 'value'],
            selectOnTab: false,
            onChange: function(value) {
                var hiddenValue = row.querySelector('[name$="[value]"]');
                if (hiddenValue) {
                    hiddenValue.value = Array.isArray(value) ? value.join(', ') : String(value || '');
                }
                updateCodePreview();
            }
        });
    }

    function renderStatusToggle(name, value) {
        var enabled = value !== 'disabled';

        return '<button type="button" class="lightswitch' + (enabled ? ' on' : '') + '" data-status-toggle role="switch" aria-checked="' + (enabled ? 'true' : 'false') + '">' +
            '<div class="lightswitch-container"><div class="handle"></div></div>' +
            '<input type="hidden" name="' + escapeHtml(name) + '" value="' + (enabled ? 'enabled' : 'disabled') + '">' +
            '</button>';
    }

    function renderAuthorPicker(name, value) {
        var values = parseTokenValues(value);

        return '<div class="super-mailer-author-picker">' +
            '<input type="hidden" name="' + escapeHtml(name) + '" value="' + escapeHtml(values.join(', ')) + '">' +
            '<div class="super-mailer-author-tokens">' + values.map(function(id) {
                return renderAuthorToken(id, 'Author ID: ' + id);
            }).join('') + '</div>' +
            '<button type="button" class="btn dashed" data-select-author>' + (values.length ? 'Add author' : 'Select author') + '</button>' +
            '<button type="button" class="delete icon' + (values.length ? '' : ' hidden') + '" data-clear-author title="Clear"></button>' +
            '</div>';
    }

    function setStatusToggle(toggle, enabled) {
        var hiddenValue = toggle.querySelector('[name$="[value]"]');
        toggle.setAttribute('aria-checked', enabled ? 'true' : 'false');
        toggle.classList.toggle('on', enabled);
        if (hiddenValue) {
            hiddenValue.value = enabled ? 'enabled' : 'disabled';
        }
    }

    function toggleStatusValue(toggle) {
        setStatusToggle(toggle, toggle.getAttribute('aria-checked') !== 'true');
        updateCodePreview();
    }

    function selectAuthor(row) {
        if (!window.Craft || typeof Craft.createElementSelectorModal !== 'function') {
            return;
        }

        Craft.createElementSelectorModal('craft\\elements\\User', {
            multiSelect: false,
            hideOnSelect: true,
            modalTitle: 'Select author',
            onSelect: function(selected) {
                var elements = Array.isArray(selected) ? selected : [selected];
                elements = elements.filter(Boolean);

                var hiddenValue = row.querySelector('[name$="[value]"]');
                var tokens = row.querySelector('.super-mailer-author-tokens');
                var button = row.querySelector('[data-select-author]');
                var clearButton = row.querySelector('[data-clear-author]');
                var selectedValues = parseTokenValues(hiddenValue ? hiddenValue.value : '');

                if (!elements.length || !hiddenValue || !tokens) {
                    return;
                }

                elements.forEach(function(element) {
                    if (selectedValues.indexOf(String(element.id)) === -1) {
                        selectedValues.push(String(element.id));
                        tokens.insertAdjacentHTML('beforeend', renderAuthorToken(element.id, element.label || element.title || ('Author ID: ' + element.id)));
                    }
                });
                if (hiddenValue) {
                    hiddenValue.value = selectedValues.join(', ');
                }
                if (button) {
                    button.textContent = 'Add author';
                }
                if (clearButton) {
                    clearButton.classList.remove('hidden');
                }
                updateCodePreview();
            }
        });
    }

    function syncConditionRow(row, resetValue) {
        var field = row.querySelector('[name$="[field]"]');
        var operatorCell = row.querySelector('[data-operator-cell]');
        var valueCell = row.querySelector('[data-value-cell]');
        if (!field || !operatorCell || !valueCell) {
            return;
        }

        var index = Array.from(conditionTable.querySelectorAll('[data-condition-row]')).indexOf(row);
        var currentValue = resetValue ? '' : (valueCell.querySelector('[name$="[value]"]') ? valueCell.querySelector('[name$="[value]"]').value : '');
        var valueName = 'conditionRules[' + index + '][value]';
        var valueType = conditionValueType(field.value);

        operatorCell.innerHTML = '<input type="hidden" name="conditionRules[' + index + '][operator]" value="equals"><span class="light">is</span>';

        if (valueType === 'toggle') {
            operatorCell.innerHTML = '<input type="hidden" name="conditionRules[' + index + '][operator]" value="equals">';
            valueCell.innerHTML = renderStatusToggle(valueName, currentValue);
            setStatusToggle(valueCell.querySelector('[data-status-toggle]'), currentValue !== 'disabled');
            return;
        }

        if (valueType === 'author') {
            operatorCell.innerHTML = '<input type="hidden" name="conditionRules[' + index + '][operator]" value="contains"><span class="light">contains</span>';
            valueCell.innerHTML = renderAuthorPicker(valueName, currentValue);
            return;
        }

        if (valueType === 'selectize') {
            operatorCell.innerHTML = '<input type="hidden" name="conditionRules[' + index + '][operator]" value="contains"><span class="light">contains</span>';
            valueCell.innerHTML = renderSelectizeValue(valueName, currentValue, field.value);
            initConditionSelectize(row);
            return;
        }

        valueCell.innerHTML = renderTextValue(valueName, currentValue, field.value);
    }

    function createConditionRow(rule) {
        var index = conditionTable.querySelectorAll('[data-condition-row]').length;
        var row = document.createElement('div');
        row.className = 'super-mailer-condition-row';
        row.setAttribute('data-condition-row', '');
        row.innerHTML = '<div class="super-mailer-condition-cell super-mailer-condition-field-cell">' +
            '<div class="select fullwidth">' +
            '<select name="conditionRules[' + index + '][field]" class="super-mailer-condition-field-input">' + conditionFieldOptions.map(function(option) {
            return '<option value="' + escapeHtml(option.value) + '"' + (rule && rule.field === option.value ? ' selected' : '') + '>' + escapeHtml(option.label) + '</option>';
        }).join('') + '</select>' +
            '</div>' +
            '</div>' +
            '<div class="super-mailer-condition-cell super-mailer-condition-operator" data-operator-cell></div>' +
            '<div class="super-mailer-condition-cell super-mailer-condition-value" data-value-cell><input type="text" name="conditionRules[' + index + '][value]" value="' + escapeHtml(rule && rule.value ? rule.value : '') + '" class="text fullwidth"></div>' +
            '<div class="super-mailer-condition-cell super-mailer-condition-actions"><button type="button" class="delete icon super-mailer-condition-remove" data-remove-condition title="Remove"></button></div>';
        conditionTable.appendChild(row);
        syncConditionRow(row);
        updateCodePreview();
    }

    if (conditionTable) {
        conditionTable.querySelectorAll('[data-condition-row]').forEach(syncConditionRow);
        conditionTable.addEventListener('change', function(event) {
            var row = event.target.closest('[data-condition-row]');
            if (!row) {
                return;
            }
            if (event.target.name && event.target.name.indexOf('[field]') !== -1) {
                syncConditionRow(row, true);
            }
            updateCodePreview();
        });
        conditionTable.addEventListener('input', updateCodePreview);
        conditionTable.addEventListener('click', function(event) {
            var row = event.target.closest('[data-condition-row]');
            if (event.target.closest('[data-remove-token]')) {
                var token = event.target.closest('[data-token-value]');
                var tokenContainer = event.target.closest('.super-mailer-author-picker');
                token.remove();
                if (tokenContainer) {
                    var hiddenAuthorValue = tokenContainer.querySelector('[name$="[value]"]');
                    var clearAuthorButton = tokenContainer.querySelector('[data-clear-author]');
                    var authorButton = tokenContainer.querySelector('[data-select-author]');
                    var authorValues = Array.from(tokenContainer.querySelectorAll('[data-token-value]')).map(function(authorToken) {
                        return authorToken.dataset.tokenValue;
                    });
                    hiddenAuthorValue.value = authorValues.join(', ');
                    if (clearAuthorButton) {
                        clearAuthorButton.classList.toggle('hidden', !authorValues.length);
                    }
                    if (authorButton) {
                        authorButton.textContent = authorValues.length ? 'Add author' : 'Select author';
                    }
                    updateCodePreview();
                }
                return;
            }
            if (event.target.closest('[data-status-toggle]')) {
                toggleStatusValue(event.target.closest('[data-status-toggle]'));
                return;
            }
            if (event.target.closest('[data-select-author]') && row) {
                selectAuthor(row);
                return;
            }
            if (event.target.closest('[data-clear-author]') && row) {
                var authorPicker = row.querySelector('.super-mailer-author-picker');
                if (authorPicker) {
                    authorPicker.querySelector('[name$="[value]"]').value = '';
                    authorPicker.querySelector('.super-mailer-author-tokens').innerHTML = '';
                    authorPicker.querySelector('[data-select-author]').textContent = 'Select author';
                    event.target.closest('[data-clear-author]').classList.add('hidden');
                }
                updateCodePreview();
                return;
            }
            if (!event.target.closest('[data-remove-condition]')) {
                return;
            }
            event.target.closest('[data-condition-row]').remove();
            updateConditionIndexes();
            conditionTable.querySelectorAll('[data-condition-row]').forEach(syncConditionRow);
            updateCodePreview();
        });
        conditionTable.addEventListener('keydown', function(event) {
            if ((event.key === 'Enter' || event.key === ' ') && event.target.closest('[data-status-toggle]')) {
                event.preventDefault();
                toggleStatusValue(event.target.closest('[data-status-toggle]'));
                return;
            }
        });
    }

    if (addConditionButton && conditionTable) {
        addConditionButton.addEventListener('click', function() {
            createConditionRow({field: conditionFieldOptions[0] ? conditionFieldOptions[0].value : '', value: ''});
        });
    }

    if (conditionMatchMode) {
        conditionMatchMode.addEventListener('change', updateCodePreview);
    }

    if (phpCondition) {
        phpCondition.addEventListener('input', updateCodePreview);
    }

    search.addEventListener('input', function() {
        var query = search.value.trim().toLowerCase();
        if (query.length < 2) {
            results.classList.add('hidden');
            return;
        }

        renderResults(events.filter(function(event) {
            return searchableText(event).indexOf(query) !== -1;
        }));
    });

    search.addEventListener('focus', function() {
        if (search.value.trim().length >= 2) {
            search.dispatchEvent(new Event('input'));
        }
    });

    results.addEventListener('click', function(event) {
        var button = event.target.closest('.super-mailer-event-option');
        if (!button) {
            return;
        }

        renderEvent(findByValue(button.dataset.value));
        results.classList.add('hidden');
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('#eventSearch') && !event.target.closest('#eventAutocompleteResults')) {
            results.classList.add('hidden');
        }
    });

    if (copyButton) {
        copyButton.addEventListener('click', function() {
            if (!currentCode) {
                return;
            }

            navigator.clipboard.writeText(currentCode).then(function() {
                var original = copyButton.textContent;
                copyButton.textContent = copiedLabel;
                setTimeout(function() {
                    copyButton.textContent = original;
                }, 1400);
            });
        });
    }

    renderEvent(findByValue(currentValue));
})();
