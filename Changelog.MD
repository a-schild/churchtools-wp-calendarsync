# churchtools-wp-calendarsync changelog

## 2025-06-21
- Release v1.0.11
- Added support for Event Manager 7.x
- Check for existing images before uploading
  Prevents duplicate uploads
- Upload images in folder with year+month of event

## 2025-04-04
- Release v1.0.10
- Set log level for flyer handling to debug when not modifying wp database
- Release v1.0.9
- Make sure to not reupload file if already present in WP
- Set log level to info

## 2024-05-13
- Release v1.0.8
- Merged in link replacement pull request from JonFStr (Thanks)
  You can now also use this macro to embedd the link in the post content
  #LINK:Mehr infos unter diesem Link:#
- Make sure link starts with http:// or https://, otherwise we prefix it with https://
- Add one file attachment, if we find a event file attachement which has "flyer"
  in the file name
  The link to the flyer can also be used with a placeholder #FLYER:Mehr infos auf dem Flyer:#

## 2024-02-15
- Release v1.0.7
  Fix issue with double quotes introduced in 1.0.6

## 2023-11-10
- Release v1.0.6
- DB column should not be unique, otherwise we can't save repeating events in mapping
- Release v1.0.5
- Fix branch condition to process categories when no resource type mapping is enabled

## 2023-11-09
- Release v1.0.4
- Allow NULL values in more columns

## 2023-11-07
- Release v1.0.3
- Fix versioning
- Fix sql data type error on new installations
- Release v1.0.2

## 2023-10-16
- Correctly handle repeating events
- Release v1.0.1

## 2023-09-29
- Change licence from Apache 2.0 to GNU GPL 2
- Add events manager plugin detection and error handling if missing/inactive
- Pass user to cron job
- More plugin metadata added
- Assign categories based on source calendar

(c) 2023 Aarboard a.schild@aarboard.ch
