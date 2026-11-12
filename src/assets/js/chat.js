document.addEventListener('DOMContentLoaded', function() {
    const chatContainer = document.getElementById('chatscribeChat');
    const closeButton = document.getElementById('chatscribeClose');
    const sendButton = document.getElementById('chatscribeSend');
    const inputField = document.getElementById('chatscribeInput');
    const messagesContainer = document.getElementById('chatscribeMessages');
    const greetingElement = document.getElementById('chatscribeGreeting');

    // Time-aware greeting, based on the visitor's own clock.
    if (greetingElement) {
        greetingElement.textContent = getGreeting();
    }

    function getGreeting() {
        const hour = new Date().getHours();
        let timeOfDay;
        if (hour < 5) {
            timeOfDay = 'Hi there, night owl';
        } else if (hour < 12) {
            timeOfDay = 'Good morning';
        } else if (hour < 17) {
            timeOfDay = 'Good afternoon';
        } else {
            timeOfDay = 'Good evening';
        }
        const siteName = (window.ChatScribe && ChatScribe.siteName) ? ChatScribe.siteName : '';
        const welcome = siteName ? ` Welcome to ${siteName}.` : '';
        return `${timeOfDay}!${welcome} How can I help you today?`;
    }

    // Close chat functionality
    closeButton.addEventListener('click', function() {
        chatContainer.style.display = 'none';
    });

    // Send message functionality
    sendButton.addEventListener('click', sendMessage);
    inputField.addEventListener('keypress', function(event) {
        if (event.key === 'Enter') {
            sendMessage();
        }
    });

    function sendMessage() {
        const userMessage = inputField.value.trim();
        if (userMessage) {
            appendMessage(userMessage, 'user');
            inputField.value = '';
            showTyping();
            setTimeout(() => {
                fetch( ChatScribe.restUrl, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': ChatScribe.nonce,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ message: userMessage }),
                })
                .then(response => response.json())
                .then(data => {
                    hideTyping();
                    if (data.status === 'success') {
                        appendMessage(data.message, 'bot');
                    } else {
                        appendMessage("Sorry, there was an error processing your message.", 'bot');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    hideTyping();
                    appendMessage("Sorry, there was an error processing your message.", 'bot');
                });
            }, 1000);
        }
    }

    function appendMessage(message, sender) {
        const messageElement = document.createElement(sender === 'bot' ? 'div' : 'p');
        messageElement.classList.add('chatscribe-message', `chatscribe-message--${sender}`);
        if (sender === 'bot') {
            // Safe: code is stripped, then everything left is escaped before formatting tags are added.
            const html = renderMarkdown(stripCode(stripEmojis(message)));
            messageElement.innerHTML = html || '<p>Sorry, I can only answer that in plain language.</p>';
        } else {
            messageElement.textContent = message;
        }
        messagesContainer.appendChild(messageElement);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Removes anything code-like so replies stay natural language only.
    function stripCode(text) {
        return String(text)
            .replace(/\r\n/g, '\n')
            // Fenced code blocks, including one the model never closed.
            .replace(/(```|~~~)[\s\S]*?(\1|$)/g, '\n')
            // Dangerous HTML elements along with everything inside them.
            .replace(/<(script|style|iframe|object|embed|svg|math|template|noscript|textarea)\b[\s\S]*?(<\/\1\s*>|$)/gi, '')
            // HTML comments and any remaining tags.
            .replace(/<!--[\s\S]*?(-->|$)/g, '')
            .replace(/<\/?[a-z][^>]*>/gi, '')
            // Images, inline code spans and stray backticks.
            .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
            // Links that aren't web links (javascript:, data:, etc.) keep only their label.
            .replace(/\[([^\]]*)\]\((?!https?:\/\/|\/)[^)]*\)\)?/g, '$1')
            .replace(/`[^`\n]*`/g, '')
            .replace(/`/g, '')
            // Tidy the gaps left behind.
            .replace(/[ \t]+([.,;:!?])/g, '$1')
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    // Turns the model's markdown (bold, italic, lists, links, headings) into HTML.
    function renderMarkdown(text) {
        const source = escapeHtml(text);
        const html = [];
        let paragraph = [];
        let list = null;

        function flushParagraph() {
            if (paragraph.length) {
                html.push('<p>' + paragraph.map(formatInline).join('<br>') + '</p>');
                paragraph = [];
            }
        }
        function flushList() {
            if (list) {
                html.push('<' + list.tag + '>' + list.items.map(item => '<li>' + formatInline(item) + '</li>').join('') + '</' + list.tag + '>');
                list = null;
            }
        }

        source.split('\n').forEach(function(line) {
            const bulletMatch = line.match(/^\s*[-*•]\s+(.*)$/);
            const numberMatch = line.match(/^\s*\d+[.)]\s+(.*)$/);
            const headingMatch = line.match(/^#{1,6}\s+(.*)$/);

            if (!line.trim()) {
                flushParagraph();
                flushList();
            } else if (headingMatch) {
                flushParagraph();
                flushList();
                html.push('<p class="chatscribe-heading">' + formatInline(headingMatch[1]) + '</p>');
            } else if (bulletMatch || numberMatch) {
                const tag = bulletMatch ? 'ul' : 'ol';
                flushParagraph();
                if (!list || list.tag !== tag) {
                    flushList();
                    list = { tag: tag, items: [] };
                }
                list.items.push((bulletMatch || numberMatch)[1]);
            } else if (list && /^\s+/.test(line)) {
                // Indented line continues the previous list item.
                list.items[list.items.length - 1] += ' ' + line.trim();
            } else {
                flushList();
                paragraph.push(line);
            }
        });
        flushParagraph();
        flushList();

        return html.join('');
    }

    // Bold, italic and links within a single line.
    function formatInline(text) {
        return text
            .replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
            .replace(/\*\*(.+?)\*\*|__(.+?)__/g, function(match, a, b) {
                return '<strong>' + (a || b) + '</strong>';
            })
            .replace(/(^|[^\w*])\*(?!\s)(.+?)\*(?!\w)/g, '$1<em>$2</em>')
            .replace(/(^|[^\w])_(?!\s)(.+?)_(?!\w)/g, '$1<em>$2</em>');
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Removes emojis (and their modifiers) from bot replies.
    function stripEmojis(text) {
        return String(text)
            .replace(/(?![©®™])[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\u{1F3FB}-\u{1F3FF}\u{FE0F}\u{200D}\u{20E3}]/gu, '')
            .replace(/ {2,}/g, ' ')
            .trim();
    }

    // Shows the bouncing "thinking" dots as a bot bubble.
    function showTyping() {
        hideTyping();
        const typingElement = document.createElement('div');
        typingElement.id = 'chatscribeTyping';
        typingElement.classList.add('chatscribe-message', 'chatscribe-message--bot', 'chatscribe-typing');
        typingElement.setAttribute('role', 'status');
        const assistantName = (window.ChatScribe && ChatScribe.assistantName) ? ChatScribe.assistantName : 'The assistant';
        typingElement.setAttribute('aria-label', `${assistantName} is thinking`);
        typingElement.innerHTML = '<span></span><span></span><span></span>';
        messagesContainer.appendChild(typingElement);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function hideTyping() {
        const typingElement = document.getElementById('chatscribeTyping');
        if (typingElement) {
            typingElement.remove();
        }
    }
});
