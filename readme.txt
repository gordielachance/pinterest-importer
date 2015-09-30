=== Pinterest Importer ===
Contributors:grosbouff
Donate link:http://bit.ly/gbreant
Tags: importer,Pinterest,pins
Requires at least: 3.5
Tested up to: 4.3.1
Stable tag: trunk
License: GPLv2 or later

Backup all your pins from Pinterest into Wordpress !  Own your data !

== Description ==

Pinterest Importer allows you to connect to your Pinterest.com account; to fetch all your pins (including from secret boards); then to import them into Wordpress.

The difference with other plugins is that it is not based on the (very limited) official Pinterest API.  
This means that you can make a full backup (instead of getting only the last x pins); but it also means the plugin may broke one day or another.
Better use it quick !

* Nice GUI
* Get pins from public boards, secret boards, and likes
* Set import options for each of your boards (which category to use, etc)
* Import original HD images from pins
* Set pin creation date as post date
* Supports both image & video pins; and set corresponding post format
* Handles hashtags,which are converted to post tags
* May be run several times as existing pins will not be imported again
* Save the original Pin informations (pin ID, source, etc) as post metas (with prefix _pinterest-)

= Donate! =
It truly took me a LOT of time to code this plugin.
If it saved you the time to backup manually a few hundred (or more!) pins, please consider converting this time into [a donation](http://bit.ly/gbreant).
This would be very appreciated — Thanks !

= Instruction =

This plugin requires at least php 5.3.

1. Go to Tools -> Pinterest Importer.
2. Select "Authentification" tab; and login to Pinterest
3. Select the "Boards Settings" tab > "Needs cache refresh", check the boards you want to import the pins from; and click/select "Cache Pins".  This will query Pinterest for your pins data (can take some time).
4. Under the "Boards Settings" tab > "Pending" (which lists the boards that have pins cached), check the boards you want to import the pins from; and click/select "Import Pins".
4. Under the "Import Pins" tab > "Pending" (which lists the pins that have not yet been imported), check the pins you want to import; and click/select "Import Pins".
5. Enjoy !

= Contributors =
[Contributors are listed here](https://github.com/gordielachance/pinterest-importer/contributors)

= Notes =

For feature request and bug reports, [please use the forums](http://wordpress.org/support/plugin/pinterest-importer#postform).

If you are a plugin developer, [we would like to hear from you](https://github.com/gordielachance/pinterest-importer). Any contribution would be very welcome.

== Installation ==

1. Upload the plugin to your blog and Activate it.
2. Go to Tools -> Pinterest Importer.

== Frequently Asked Questions ==

= I'm not happy with the content created for posts imported.  How can I change that ? =
You can set the content you want by using the filter "pinim_get_post_content", see function set_post_content() from class 'Pinim_Pin'.

== Screenshots ==

1. Boards settings


== Changelog ==
= 0.2.6 =
* Fixed "Error getting App Version"
= 0.2.5 =
* fixed anonymous functions (closures) that were broken with old php versions : inherit variables from the parent scope with 'use' (http://www.php.net/manual/en/functions.anonymous.php)
= 0.2.4 =
* Improved remote image download + merged pinim_fetch_remote_image() and pinim_process_post_image() into pinim_attach_remote_image() 
* Added "updated" sortable column for pins (when have been processed)
* Fixed boards / pins sortable columns
* Fixed missing slash in pin's get_remote_url()
= 0.2.3 =
* Added support for likes
* Warning for users who don't have sessions enabled
= 0.2.2 =
* Small bugs fixes
= 0.2.1 =
* Small bugs fixes
= 0.2.0 =
* Fully rewritten !  No more needs to save / upload an HTML file.  SO COOL !
= 0.1.3 =
* Replaced http:// by https:// in pinim_get_pin_url(); pinim_get_user_url(); pinim_get_board_url(); because wp_remote_get() was returning 301.
= 0.1.2 =
* Updated plugin's readme.txt
* quoted_printable_decode() to decode MHTML
* Uploaded file needs to be MHTML to allow parsing
* Improved feedback
* Updated "a.creditItem" selector to ".creditItem a" in get_pin_board() and get_pin_source()
= 0.1.1 =
* Improved code (splitted into classes)
* Hashtags are now saved as post tags
= 0.1 ==
* First release


== Upgrade Notice ==

== Localization ==
