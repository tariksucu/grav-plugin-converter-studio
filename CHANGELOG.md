<!-- prettier-ignore-start -->
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
