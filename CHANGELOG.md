<!-- prettier-ignore-start -->
# v1.0.1
## 2026-11-23

1. [](#bugfix)
    * Kept originals are stored as `<name>.original.txt` (`IMG_0042.MOV.original.txt`), which Grav's web server rules refuse to serve; before, MOV, WAV, FLAC and BMP originals could be downloaded from `user/data/converter-studio/originals`. Originals kept by 1.0.0 are renamed the same way the first time the plugin runs after the update (an upload, a page save, the scheduler job or a command); a rename that stops half-way goes on the next time
    * A conversion that fails no longer turns the upload into an error: the file is stored as uploaded, the other listeners of the upload (the API's audit trail and webhooks among them) all run, and the reason goes to Grav's log and to `bin/plugin converter-studio status`
    * `onApiMediaUploaded` and `onAdminAfterAddMedia` are subscribed at priority -1000, after other listeners
    * The API key is only sent to `https://converter.studio/api`, or to an address set in the `CONVERTER_STUDIO_API_URL` environment variable; the API address is no longer a setting, and an `api_url` left in the settings is ignored (the status command says so)
2. [](#improved)
    * Requests that convert nothing only read the plugin's settings; the API client is made when an upload, a page save, the scheduler job or a command needs it
    * The scheduler job stops after about 20 seconds (it was 50) and leaves what is unfinished for its next run; a result download already under way may finish first

# v1.0.0
## 2026-11-23

1. [](#new)
    * Converts Admin uploads as they arrive, one kind of file at a time: iPhone photos (HEIC, HEIF), camera RAW photos (DNG, CR2, NEF, ARW), TIFF and BMP images to JPG, WebP or PNG; MOV, AVI, MKV, WMV, FLV and 3GP videos to MP4 or WebM; Office and OpenDocument files to PDF; WAV, AIFF, FLAC and WMA to MP3
    * Works with the classic Admin on Grav 1.7 and with Admin 2 on Grav 2 (page media and the Media page)
    * Lets the Admin accept HEIC and camera RAW uploads once their conversion is switched on
    * Keeps the original in `user/data/converter-studio/originals` (not public), next to the converted file, or deletes it, as set
    * Moves a file's title, alt text and caption (`.meta.yaml`) to the converted file
    * Points a page's links to a converted file (`photo.heic` → `photo.jpg`) when the page is saved
    * Conversions that outlast an upload, such as long videos, finish through Grav's scheduler or `bin/plugin converter-studio work`
    * A conversion that would cost more credits than allowed waits for approval (`bin/plugin converter-studio confirm`)
    * `bin/plugin converter-studio convert` for media already on the site, by page, for every page or for the Media page
    * `bin/plugin converter-studio status` for the account, the settings and the conversions in progress
<!-- prettier-ignore-end -->
