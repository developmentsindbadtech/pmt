/**
 * Shared @mention autocomplete for boards and sheets.
 * Textareas: class="js-mention-textarea"
 *   data-mention-board-id="..."  OR  data-mention-sheet-id="..."
 */
(function () {
    if (typeof window.initMentions !== 'undefined') {
        return;
    }
    window.initMentions = true;

    let mentionUsers = [];
    let mentionDropdown = null;
    let currentTextarea = null;
    let currentCursorPos = 0;
    let currentSearch = '';
    let loadedForKey = null;

    function initMentionAutocomplete() {
        document.addEventListener('input', function (e) {
            if (!e.target.classList.contains('js-mention-textarea')) return;

            const textarea = e.target;
            const endpoint = mentionEndpoint(textarea);
            if (!endpoint) return;

            const cursorPos = textarea.selectionStart;
            const text = textarea.value;
            const textBeforeCursor = text.substring(0, cursorPos);
            const match = textBeforeCursor.match(/@(\w*)$/);

            if (match) {
                currentTextarea = textarea;
                currentCursorPos = cursorPos;
                currentSearch = match[1].toLowerCase();

                if (!mentionDropdown) {
                    createMentionDropdown();
                }

                loadMentionUsers(endpoint);
                showMentionDropdown(textarea, match.index);
            } else {
                hideMentionDropdown();
            }
        });

        document.addEventListener('keyup', function (e) {
            if (!e.target.classList.contains('js-mention-textarea')) return;
            if (!mentionDropdown || !mentionDropdown.classList.contains('show')) return;

            const textarea = e.target;
            const cursorPos = textarea.selectionStart;
            const text = textarea.value;
            const textBeforeCursor = text.substring(0, cursorPos);
            const match = textBeforeCursor.match(/@(\w*)$/);

            if (match) {
                currentCursorPos = cursorPos;
                showMentionDropdown(textarea, match.index);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (!mentionDropdown || !mentionDropdown.classList.contains('show') || !currentTextarea) return;
            if (e.target !== currentTextarea) return;

            const items = mentionDropdown.querySelectorAll('.mention-item');
            if (items.length === 0) return;

            const selected = mentionDropdown.querySelector('.mention-item.selected');
            let nextSelected = null;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                e.stopPropagation();
                if (selected) {
                    selected.classList.remove('selected');
                    nextSelected = selected.nextElementSibling || items[0];
                } else {
                    nextSelected = items[0];
                }
                if (nextSelected) {
                    nextSelected.classList.add('selected');
                    nextSelected.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                e.stopPropagation();
                if (selected) {
                    selected.classList.remove('selected');
                    nextSelected = selected.previousElementSibling || items[items.length - 1];
                } else {
                    nextSelected = items[items.length - 1];
                }
                if (nextSelected) {
                    nextSelected.classList.add('selected');
                    nextSelected.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                if (selected || items[0]) {
                    e.preventDefault();
                    e.stopPropagation();
                    insertMention((selected || items[0]).dataset.name);
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                hideMentionDropdown();
            }
        });

        document.addEventListener('click', function (e) {
            if (mentionDropdown && !mentionDropdown.contains(e.target) && e.target !== currentTextarea) {
                hideMentionDropdown();
            }
        });
    }

    function mentionEndpoint(textarea) {
        const boardId = textarea.getAttribute('data-mention-board-id');
        if (boardId) {
            return '/api/boards/' + boardId + '/mentionable-users';
        }
        const sheetId = textarea.getAttribute('data-mention-sheet-id');
        if (sheetId) {
            return '/api/sheets/' + sheetId + '/mentionable-users';
        }
        return null;
    }

    function createMentionDropdown() {
        mentionDropdown = document.createElement('div');
        mentionDropdown.className = 'mention-dropdown';
        mentionDropdown.style.cssText = 'display: none; position: fixed; background: white; border: 1px solid #e0e0e0; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 200px; overflow-y: auto; z-index: 10000; min-width: 200px;';
        document.body.appendChild(mentionDropdown);
    }

    function loadMentionUsers(endpoint) {
        if (loadedForKey === endpoint && mentionUsers.length) {
            renderMentionDropdown();
            return;
        }
        fetch(endpoint, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((users) => {
                mentionUsers = users;
                loadedForKey = endpoint;
                renderMentionDropdown();
            })
            .catch(() => {
                mentionUsers = [];
                loadedForKey = null;
                renderMentionDropdown();
            });
    }

    function renderMentionDropdown() {
        if (!mentionDropdown) return;

        const filtered = mentionUsers
            .filter((u) => !currentSearch || (u.search || '').includes(currentSearch))
            .slice(0, 10);

        if (filtered.length === 0) {
            mentionDropdown.innerHTML = '<div style="padding: 8px 12px; color: #999; font-size: 12px;">No users found</div>';
            return;
        }

        mentionDropdown.innerHTML = filtered
            .map(
                (user, idx) => `
            <div class="mention-item${idx === 0 ? ' selected' : ''}" data-name="${escapeAttr(user.name)}" style="padding: 8px 12px; cursor: pointer; font-size: 13px;${idx === 0 ? ' background: #f0f0f0;' : ''}">
                ${escapeHtml(user.name)}
            </div>
        `
            )
            .join('');

        mentionDropdown.querySelectorAll('.mention-item').forEach((item) => {
            item.addEventListener('mouseenter', function () {
                mentionDropdown.querySelectorAll('.mention-item').forEach((i) => i.classList.remove('selected'));
                this.classList.add('selected');
            });
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                insertMention(this.dataset.name);
            });
        });
    }

    function showMentionDropdown(textarea, startPos) {
        if (!mentionDropdown) return;

        const rect = textarea.getBoundingClientRect();
        const text = textarea.value.substring(0, startPos);
        const lines = text.split('\n');
        const currentLine = lines.length - 1;
        const textareaStyle = window.getComputedStyle(textarea);
        const lineHeight = parseFloat(textareaStyle.lineHeight) || 20;
        const paddingTop = parseFloat(textareaStyle.paddingTop) || 0;
        const paddingLeft = parseFloat(textareaStyle.paddingLeft) || 0;
        const borderTop = parseFloat(textareaStyle.borderTopWidth) || 0;
        const currentLineText = lines[currentLine] || '';
        const atIndex = currentLineText.lastIndexOf('@');
        const textBeforeAt = currentLineText.substring(0, atIndex);

        const measureSpan = document.createElement('span');
        measureSpan.style.visibility = 'hidden';
        measureSpan.style.position = 'absolute';
        measureSpan.style.whiteSpace = 'pre';
        measureSpan.style.font = textareaStyle.font;
        measureSpan.textContent = textBeforeAt;
        document.body.appendChild(measureSpan);
        const textWidth = measureSpan.offsetWidth;
        document.body.removeChild(measureSpan);

        const cursorY = rect.top + paddingTop + borderTop + currentLine * lineHeight + lineHeight * 0.8;
        const cursorX = rect.left + paddingLeft + textWidth;
        const dropdownHeight = 200;
        const spaceBelow = window.innerHeight - cursorY;
        const spaceAbove = cursorY;

        let topPosition;
        if (spaceBelow >= dropdownHeight + 10) {
            topPosition = cursorY + 2;
        } else if (spaceAbove >= dropdownHeight + 10) {
            topPosition = cursorY - dropdownHeight - 2;
        } else {
            topPosition = spaceBelow > spaceAbove ? cursorY + 2 : Math.max(10, cursorY - dropdownHeight - 2);
        }

        mentionDropdown.style.top = Math.max(10, Math.min(topPosition, window.innerHeight - dropdownHeight - 10)) + 'px';
        mentionDropdown.style.left = Math.max(10, Math.min(cursorX, window.innerWidth - 220)) + 'px';
        mentionDropdown.style.display = 'block';
        mentionDropdown.classList.add('show');
        renderMentionDropdown();
    }

    function hideMentionDropdown() {
        if (mentionDropdown) {
            mentionDropdown.style.display = 'none';
            mentionDropdown.classList.remove('show');
        }
        currentTextarea = null;
    }

    function insertMention(name) {
        if (!currentTextarea) return;

        const text = currentTextarea.value;
        const textBeforeCursor = text.substring(0, currentCursorPos);
        const match = textBeforeCursor.match(/@(\w*)$/);

        if (match) {
            const start = currentCursorPos - match[0].length;
            const newText = text.substring(0, start) + '@' + name + ' ' + text.substring(currentCursorPos);
            currentTextarea.value = newText;
            const newPos = start + name.length + 2;
            currentTextarea.setSelectionRange(newPos, newPos);
            currentTextarea.focus();
            currentTextarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        hideMentionDropdown();
    }

    window.insertMention = insertMention;

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(str) {
        return String(str).replace(/"/g, '&quot;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMentionAutocomplete);
    } else {
        initMentionAutocomplete();
    }
})();
