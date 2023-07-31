This is a patched version of https://wordpress.org/plugins/staticfeed/, which is no longer maintained on wordpress.org.

See the readme.txt for how this plugin is supposed to work.

## Testing

The plugin creates static feed files under `wp-content/staticfeed/`.
Remove those files and then resave the plugin settings. The files should be recreated.

Now make a change to the `rss2.xml` file in that directory.
Then open `https://yoursite.com/feed/` in a browser and check that the change is visible.

If it is, then the plugin and the redirect are working as expected.

You can also create a new post and see that appearing in the feed after publishing.