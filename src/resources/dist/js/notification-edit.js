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
    var selectedEvent = generator.querySelector('.super-mailer-selected-event');
    var code = document.getElementById('eventExampleCode');
    var copyButton = document.getElementById('copyEventCode');
    var variables = document.getElementById('eventVariableList');
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
        var pattern = /(\/\/[^\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\$[a-zA-Z_][a-zA-Z0-9_]*|\b(?:use|function|class|return|if|else|new|true|false|null)\b|\bEVENT_[A-Z0-9_]+\b|\b[A-Z][a-zA-Z0-9_\\]*(?=::class|::EVENT_| \$event|;)|\bon(?=\()|::|=>|[()[\]{};,])/g;
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
        currentCode = event.code || '';
        code.innerHTML = highlightPhp(currentCode);
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
