# Build and deployment

To release a new build do the following steps

- Update the Changelog.md
- Increase the version in the churchtools-wpcalendarsync.php file
  in line 13 and 36, for example to 1.0.3
- Create a tag in the form v1.0.3
- Commit the changes and push the tags
  An automatic release and build will be done
  via github actions, resulting in the new zip file

(c) 2023 Aarboard a.schild@aarboard.ch
