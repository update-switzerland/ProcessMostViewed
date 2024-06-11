# UpdMostViewed ProcessWire Module
## Track Page Views and List «Most Viewed» Pages
The UpdMostViewed module for ProcessWire enables you to track page views and return a list of the most viewed pages within a given time range.<br>
This module is ideal for creating sidebar-widgets, for example, a "Most Read Articles of the Week" display.

<br>

## Getting Started
Installing the UpdMostViewed module to your ProcessWire project is simple, choose one of the following methods:

- **Modules Directory:** Head into your ProcessWire backend, go to Modules > New and search for the Module **UpdMostViewed**. 
- **From GitHub:** Download the module directly from [Latest Releases on GitHub](https://github.com/update-switzerland/UpdMostViewed/releases/latest).
- **Using Composer:** If you're using Composer for your project, you can add the module by running the command `composer require update-switzerland/updmostviewed` in your project root directory.

With the module downloaded and installed, configure it to best suit your needs. Toggle on the "Automated Page View Counting" for hands-free tracking.

<br>

## Usage
### Manual View Tracking
While automatic tracking is recommended and easier to use, you can also manually track page views by adding the following line to your page templates
```php
$modules->get('UpdMostViewed')->writePageView($page);
```
In this case, $page should be a ProcessWire Page object.<br>
Manual view counting will still respect any settings for exclusions (IPs, crawlers, etc.).

<br>
<hr style="background:#090;" />

### Automatic View Tracking (Recommended)
To use automatic tracking, simply go to the MostViewed module configuration and enable "Automated Page View Counting".

<br>

### Module Configuration
The MostViewed module provides numerous configuration options:
 - Exclude certain branches (page ids and all their children)
 - Exclude specific pages
 - Exclude certain IPs
 - Restrict counting to specific templates
 - Define which user roles to count
 - Choose to ignore views from search engine crawlers

Additionally, you can define multiple time ranges. If the module does not find enough views in the first time range, it will consider the second, and so on.<br>
Default values for the time ranges are 1 day, 2 days, and 3 days.

<br>

### Retrieving the «Most Viewed» Pages
You can retrieve an array of the most viewed pages with the following code:
```php
$mostViewed = $modules->get('UpdMostViewed')->getMostViewedPages();
```

This function can also take an array of options as a parameter to fine-tune the search for the most viewed pages.

```php
$options = [
  'templates' => 'basic-page,news-entry', // Restrict search to specific templates (comma separated)
  'limit' => 5, // Limit the number of pages returned
  'viewRange' => 1440 // Set a custom view-range in minutes
];
$mostViewed = $modules->get('UpdMostViewed')->getMostViewedPages($options);
```

Once you have retrieved the most viewed pages, you can output them with a foreach loop:
```php
echo "<ol>";
foreach ($mostViewed as $key => $most) {
  echo "<li><a href='{$most->url}'>{$most->title}</a></li>";
}
echo "</ol>";
```

<br>

### Displaying the «Most Viewed» Pages with AJAX
If your site uses cached pages, you might find that your list of "Most Viewed" pages falls out of date quickly. To keep your list current, consider using AJAX to retrieve real-time view data. You can do this by requesting the URL /?getMostViewedContent with optional argument.<br>
Here is a jQuery example of AJAX integration for this module, showing how to limit results and provide a custom view range.

```HTML
<div id="most-viewed-container">
  <h3>Most viewed (Ajax load)</h3>
  <ol class="most-viewed-list">Loading...<ol>
</div>
<script>
// load most viewed pages into page
$(document).ready(function() {
  const url = `<?php echo $modules->get('UpdMostViewed')->getVarAjaxLoad; ?>?lang=<?php echo $user->lang->name; ?>&templates=basic-page,news-page&limit=4&viewRange=1440`;
  $.ajax(
    `/${url}`,
    {
      success: function(data) {
        $('#most-viewed-container .most-viewed-list').html(data);
      },
      error: function() {
        $('#most-viewed-container .most-viewed-list').html('Sorry, currently no data available');
      }
    }
  );
});
</script>
```
