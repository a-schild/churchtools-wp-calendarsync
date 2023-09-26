<div>
 	<h2>Settings for ChurchTools Importer</h2>
 	<div>Just modify the fields below:</div>
 	<div>
 		<form method="post" class="ctwpsync_settings" action="" data-action="save_ctwpsync_settings">
		<br>ChurchTools-URL (Including https://)<br>
		<input type="text" size="30" name="ctwpsync_url" id="ctwpsync_url" class="text_box" placeholder="https://yourchurch.church.tools/" value="<?php echo $saved_data ? $saved_data['url'] : '' ; ?>" required> 
		<br>ChurchTools API token<br>
		<input type="password" size="30" name="ctwpsync_apitoken" id="ctwpsync_apitoken" class="text_box" placeholder="my login token" value="<?php echo $saved_data ? $saved_data['apitoken'] : '' ; ?>"> 
		<br>Calendar IDs (for example 2,79,62,70,78,32)<br>
		<input type="text" size="30" name="ctwpsync_ids" id="ctwpsync_ids" class="text_box" placeholder="42,43,52" value="<?php echo $saved_data ? implode(', ',$saved_data['ids']) : '' ; ?>" required> 
		<br>Calendar import past days<br>
		<input type="text" size="30" name="ctwpsync_import_past" id="ctwpsync_import_past" class="text_box" placeholder="0" value="<?php echo $saved_data ? $saved_data['import_past'] : '' ; ?>" required> 
		<br>Calendar import future days<br>
		<input type="text" size="30" name="ctwpsync_import_future" id="ctwpsync_import_future" class="text_box" placeholder="380" value="<?php echo $saved_data ? $saved_data['import_future'] : '' ; ?>" required> 
		<input type="submit" value="Save">
		<p><strong>Last updated:</strong> <?php echo $lastupdated; ?></p>
 	</div>
</div>
