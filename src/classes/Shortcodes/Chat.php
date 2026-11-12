<?php namespace ChatScribe\Shortcodes;

class Chat {

    public function __construct() {
        add_shortcode( 'chatscribe', [$this, 'render_shortcode'] );
    }

    public function render_shortcode( $atts ) {
        ob_start();
        ?>
        <style>
            #chatscribeChat {
                position: fixed;
                bottom: 20px;
                right: 20px;
                width: 340px;
                max-width: calc(100vw - 32px);
                height: 460px;
                max-height: calc(100vh - 40px);
                display: flex;
                flex-direction: column;
                background: #ffffff;
                border-radius: 16px;
                box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
                overflow: hidden;
                z-index: 9999;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                font-size: 14px;
                line-height: 1.4;
                color: #1f2937;
                box-sizing: border-box;
            }
            #chatscribeChat *,
            #chatscribeChat *::before,
            #chatscribeChat *::after {
                box-sizing: border-box;
            }

            /* Header */
            #chatscribeChat .chatscribe-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 16px;
                background: #4f46e5;
                color: #ffffff;
            }
            #chatscribeChat .chatscribe-title {
                margin: 0;
                font-size: 15px;
                font-weight: 600;
            }
            #chatscribeClose {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                padding: 0;
                background: transparent;
                color: #ffffff;
                border: none;
                border-radius: 50%;
                cursor: pointer;
                transition: background 0.15s ease;
            }
            #chatscribeClose:hover {
                background: rgba(255, 255, 255, 0.2);
            }

            /* Message box */
            #chatscribeMessages {
                flex: 1;
                display: flex;
                flex-direction: column;
                gap: 8px;
                padding: 16px;
                overflow-y: auto;
                background: #f5f6fa;
            }
            #chatscribeChat .chatscribe-message {
                max-width: 80%;
                margin: 0;
                padding: 9px 13px;
                border-radius: 16px;
                word-wrap: break-word;
            }
            #chatscribeChat .chatscribe-message--bot {
                align-self: flex-start;
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-bottom-left-radius: 4px;
            }
            #chatscribeChat .chatscribe-message--user {
                align-self: flex-end;
                background: #4f46e5;
                color: #ffffff;
                border-bottom-right-radius: 4px;
            }

            /* Input row */
            #chatscribeChat .chatscribe-input-row {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px;
                background: #ffffff;
                border-top: 1px solid #e5e7eb;
            }
            #chatscribeInput {
                flex: 1;
                min-width: 0;
                height: 40px;
                margin: 0;
                padding: 0 14px;
                font: inherit;
                color: inherit;
                background: #f5f6fa;
                border: 1px solid #e5e7eb;
                border-radius: 20px;
                outline: none;
                transition: border-color 0.15s ease, box-shadow 0.15s ease;
            }
            #chatscribeInput:focus {
                border-color: #4f46e5;
                box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
            }
            #chatscribeSend {
                flex-shrink: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                padding: 0;
                background: transparent;
                color: #4f46e5;
                border: none;
                border-radius: 50%;
                cursor: pointer;
                transition: color 0.15s ease, transform 0.15s ease;
            }
            #chatscribeSend:hover {
                color: #4338ca;
                transform: scale(1.08);
            }
            #chatscribeSend:focus-visible,
            #chatscribeClose:focus-visible {
                outline: 2px solid #4f46e5;
                outline-offset: 2px;
            }
            #chatscribeClose:focus-visible {
                outline-color: #ffffff;
            }
        </style>

        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                const chatContainer = document.getElementById('chatscribeChat');
                const closeButton = document.getElementById('chatscribeClose');
                const sendButton = document.getElementById('chatscribeSend');
                const inputField = document.getElementById('chatscribeInput');
                const messagesContainer = document.getElementById('chatscribeMessages');

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
                        console.log('test', userMessage);
                        appendMessage(userMessage, 'user');
                        inputField.value = '';
                        setTimeout(() => {
                            fetch( "<?php echo esc_js( 'wp-json/' . \ChatScribe\Api\Chat::CHAT_REST_NAME ); ?>", {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({ message: userMessage }),
                            })
                            .then(response => response.json())
                            .then(data => {
                                console.log('data', data); 
                                if (data.status === 'success') {
                                    appendMessage(data.message, 'bot');
                                } else {
                                    appendMessage("Sorry, there was an error processing your message.", 'bot');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                appendMessage("Sorry, there was an error processing your message.", 'bot');
                            });
                        }, 1000);
                    }
                }

                function appendMessage(message, sender) {
                    const messageElement = document.createElement('p');
                    messageElement.classList.add('chatscribe-message', `chatscribe-message--${sender}`);
                    messageElement.textContent = message;
                    messagesContainer.appendChild(messageElement);
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                }
            });
        </script>
        // it goes to gra 
        <div id="chatscribeChat">
            <div class="chatscribe-header">
                <p class="chatscribe-title">ChatScribe</p>
                <button type="button" id="chatscribeClose" aria-label="Close chat">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>
            </div>

            <div id="chatscribeMessages">
                <p class="chatscribe-message chatscribe-message--bot">Welcome to ChatScribe! How can I help?</p>
            </div>

            <div class="chatscribe-input-row">
                <input type="text" id="chatscribeInput" placeholder="Type your message..." autocomplete="off">
                <button type="button" id="chatscribeSend" aria-label="Send message">
                    <svg width="40" height="40" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="11" fill="currentColor"/>
                        <path d="M10 7.5v9l6.5-4.5z" fill="#ffffff"/>
                    </svg>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
