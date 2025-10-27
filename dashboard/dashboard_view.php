<div>
 	<h2>Settings for ChurchTools Calendar Sync</h2>
 	<div>Just modify the fields below:</div>
 	<div>
 		<form method="post" class="ctwpsync_settings" action="" data-action="save_ctwpsync_settings">
		<br>ChurchTools-URL (Including https://)<br>
		<input type="text" size="30" name="ctwpsync_url" id="ctwpsync_url" class="text_box" placeholder="https://yourchurch.church.tools/" value="<?php echo $saved_data ? $saved_data['url'] : '' ; ?>" required> 
		<br>ChurchTools API token<br>
		<input type="password" size="30" name="ctwpsync_apitoken" id="ctwpsync_apitoken" class="text_box" placeholder="my login token" value="<?php echo $saved_data ? $saved_data['apitoken'] : '' ; ?>"> 
		<br>Calendar IDs (for example 2,32,62,70,78,79)<br>
		<input type="text" size="30" name="ctwpsync_ids" id="ctwpsync_ids" class="text_box" placeholder="42,43,52" value="<?php echo ($saved_data && array_key_exists('ids', $saved_data)) ? implode(', ',$saved_data['ids']) : '' ; ?>" required> 
		<br>Calendar Categories (for example Gottesdienste,Musik,Romands,,,Kinder)<br>
        Must match the order of the calendar ids from above<br>
		<input type="text" size="30" name="ctwpsync_ids_categories" id="ctwpsync_ids_categories" class="text_box" placeholder="Gottesdienste,Musik,,,Kinder" value="<?php echo ($saved_data && array_key_exists('ids_categories', $saved_data) && count($saved_data['ids_categories'])>0) ? implode(', ',$saved_data['ids_categories']) : '' ; ?>" > 
		<br>Calendar sync past days<br>
		<input type="text" size="30" name="ctwpsync_import_past" id="ctwpsync_import_past" class="text_box" placeholder="0" value="<?php echo $saved_data ? $saved_data['import_past'] : '' ; ?>" required> 
		<br>Calendar sync future days<br>
		<input type="text" size="30" name="ctwpsync_import_future" id="ctwpsync_import_future" class="text_box" placeholder="380" value="<?php echo $saved_data ? $saved_data['import_future'] : '' ; ?>" required> 
		<br>Resource type for categories, use -1 to disable categories management<br>
		<input type="text" size="30" name="ctwpsync_resourcetype_for_categories" id="ctwpsync_resourcetype_for_categories" class="text_box" placeholder="-1" value="<?php echo $saved_data ? $saved_data['resourcetype_for_categories'] : '' ; ?>" required> 
		<input type="submit" value="Save">
		<p><strong>Last updated:</strong> <?php echo $lastupdated; ?></p>
		<p><strong>Sync duration:</strong> <?php echo $lastsyncduration; ?></p>
 	</div>

	<hr>
	<h3>Events Manager 7.1+ Compatibility</h3>
	<?php
	$migration_completed = get_option('ctwpsync_em71_migration_completed');
	if ($migration_completed) {
		echo '<p style="color: green;">✓ Migration to Events Manager 7.1+ completed</p>';
		echo '<p>All existing events have been updated with the correct event_type and post_status for Events Manager 7.1+</p>';
	} else {
		echo '<p style="color: orange;">⚠ Migration pending</p>';
		echo '<p>Existing events need to be migrated for Events Manager 7.1+ compatibility.</p>';
		echo '<p>The migration will run automatically on the next plugin load or sync cycle.</p>';
		echo '<p>To manually trigger the migration now, reload this page.</p>';
	}
	?>
</div>
