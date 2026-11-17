=== ChatScribe ===
Contributors: Good Work Marketing, Claudette Raynor
Tags: ai, chatbot, assistant, summary, content
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI assistant for your site: a chat for visitors and editors, plus post summaries and AI writing help right in the editor.

== Description ==

ChatScribe adds an AI assistant to WordPress that knows your site. It answers questions using your own content, and helps you summarize and write posts without leaving the editor.

It uses the AI Client built into WordPress, so it works with whichever AI provider you've connected under Settings > Connectors.

= Features =

* **Site chat** – A chat window on your site where visitors can ask about your posts, pages and products. Signed-in WooCommerce customers can also ask about their own orders.
* **Admin chat** – A chat in the WordPress admin for your team. It can find drafts and other unpublished content, and link straight to the edit screen.
* **AI summaries** – A "Summarize" button in the editor sidebar turns a post into a short list of key points, ready to copy into a block. You choose how many points and how long each one can be.
* **AI writer** – Describe what you want in the editor sidebar, and ChatScribe looks up what your site already has on the subject, then writes a draft. It's added to the end of the post as a group block called "AI Post Content", ready for you to edit.

= You stay in control =

* Choose which post types, categories and statuses the assistant can use, separately for the site and the admin.
* Choose which user roles can use each feature.
* The assistant never shows anyone more than they could already see themselves.
* Passwords, API keys and other sensitive values are always hidden.
* The AI writer only uses published content, and it can't create, change or delete anything on its own.

== Installation ==

1. Upload the `chatscribe` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate ChatScribe.
3. Connect an AI provider under Settings > Connectors.
4. Go to Settings > ChatScribe to turn on the features you want and choose what the assistant can use.

== Frequently Asked Questions ==

= Do I need an AI account? =

Yes. ChatScribe uses the AI provider you connect in WordPress, so you'll need an account and API key with that provider. Usage is billed by them.

= Is my content sent anywhere? =

Yes. When someone uses the chat, a summary or the writer, the relevant content is sent to your AI provider so it can respond. Only content you've allowed in the settings is used.

How that content is handled is up to your provider, and their privacy and training terms vary. Many providers only use API requests to generate a response, don't train their models on them, and keep only limited logs. It's worth checking your provider's terms to be sure especially if you plan on utilizing this to access customer order and contact information.

= Does it work with WooCommerce? =

Yes. The assistant can answer questions about products, customers can ask about their own orders, and your team can look up orders and customers from the admin chat if you allow it.

= Will the AI writer publish posts on its own? =

No. It adds a drafted group of blocks to the post you're editing. Nothing is saved or published until you do it yourself.

== Changelog ==

= 0.1.0 =
* First release.
