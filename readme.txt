=== Pinterest.com Importer ===
Contributors:grosbouff
Donate link:http://bit.ly/gbreant
Tags: importer,Pinterest,pins
Requires at least: 3.5
Tested up to: 3.2
Stable tag: trunk
License: GPLv2 or later

Import images & videos from a Pinterest.com account.

== Description ==

Import images & videos from a Pinterest.com account !
The plugin will grab your images & videos from Pinterest and create new posts with it.
Boards are saved as categories under the parent category "Pinterest.com", so it's easy for you to handle them later.
You can run it multiple time as it won't save twice the same pin.
The data from Pinterest (pin ID, board ID, content source, etc) is saved as post metas (with prefix _pinterest-) in case you need the original Pinterest informations.

The post content is
* for images, the full-sized image with a link to the original source
* for videos, a [shortcode from the Jetpack plugin](http://jetpack.me/support/shortcode-embeds/)
See the FAQ for more information.

In case you use this plugin to make a Pinterest.com backup, I suggest you setup a new Wordpress blog to run the plugin on it.
You will then be able to export the posts & images generated and import them in another blog with the Wordpress import/export tool.

= Donate! =
It took me a lot of time to make of my original code a plugin available for everyone:
If it saved you the time to backup manually a few hundred (or more!) pins, please consider converting this time into [a donation](http://bit.ly/gbreant)...
Thanks !

= Contributors =
[Contributors are listed here](https://github.com/gordielachance/pinterest-importer/contributors)

= Notes =

For feature request and bug reports, [please use the forums](http://wordpress.org/support/plugin/pinterest-importer#postform).

If you are a plugin developer, [we would like to hear from you](https://github.com/gordielachance/pinterest-importer). Any contribution would be very welcome.

== Installation ==

1. Upload the plugin to your blog and Activate it.
2. Go to the Tools -> Import screen, Click on Pinterest
3. Follow the instructions

== Frequently Asked Questions ==

= I'm not happy with the content created for posts imported.  How can I change that ? =
You can set the content you want by using the filters "pinterest_importer_post_format_content" and "pinterest_importer_post_extra_content".
They are located inside function process_post_content().

== Screenshots ==


== Changelog ==

= 0.1.1 =
* Check for duplicate images
* Uploaded files names: 49 chars max.
* Pinterest.com parent category for any posts
= 0.1 =
* First release

== Upgrade Notice ==

== Localization ==