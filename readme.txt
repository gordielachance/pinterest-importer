=== Pinterest.com Importer ===
Contributors:grosbouff
Donate link:http://bit.ly/gbreant
Tags: importer,Pinterest,pins
Requires at least: 3.5
Tested up to: 3.9
Stable tag: trunk
License: GPLv2 or later

Import pins from a Pinterest.com account.

== Description ==

Import pins from a Pinterest.com account !
Useful to backup your whole Pinterest account and convert pins to Wordpress posts.
You can run it multiple time as it won't save twice the same pin.

* Supports both image & video pins (uses post formats)
* Supports featured images
* Boards are saved as categories under the main category "Pinterest.com"
* Handles hashtags,which are converted to post tags
* Saves the Pinterest informations (pin ID, source, etc) as post metas (with prefix _pinterest-)

It is not able (yet) to get the time the item was pinned.

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
2. Go to the Tools -> Import screen, Click on Pinterest.
3. Follow the instructions.

== Frequently Asked Questions ==

= I'm not happy with the content created for posts imported.  How can I change that ? =
You can set the content you want by using the filter "pinim_get_post_content", see function Pinterest_Importer::set_post_content().

== Screenshots ==


== Changelog ==

= TODO =
* Wait for script to have stopped before be allowed to run it again
* Feedback message for terms (categories and tags)
* 2 step screen with author / category default
* login with username and password to allow to fetch private pins

= 0.1.1 =
* Improved code (splitted into classes)
* Hashtags are now saved as post tags
= 0.1 ==
* First release


== Upgrade Notice ==

== Localization ==