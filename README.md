# Converter Studio Plugin for Grav

Converts the files that browsers and Grav cannot use into ones they can, when they are uploaded in the
Admin, and on demand for the media already on your site:

- **iPhone photos (HEIC, HEIF) become JPG** (or WebP or PNG). Only Safari shows HEIC, Grav cannot make
  thumbnails or resized copies of it, and the classic Admin refuses HEIC uploads altogether.
- **Camera RAW photos (DNG, CR2, NEF, ARW), TIFF and BMP images become JPG** (or WebP or PNG), which
  Grav's image functions (`resize`, `cropZoom`, `derivatives`…) and every browser can use.
- **Videos (MOV, AVI, MKV, WMV, FLV, 3GP) become MP4 (H.264)** or WebM, which play in every browser.
- **Office documents (Word, Excel, PowerPoint, OpenDocument, RTF) become PDF**, which visitors open in
  the browser.
- **Uncompressed audio (WAV, AIFF, FLAC, WMA) becomes MP3.**

The work is done by [Converter Studio](https://converter.studio) through its
[API](https://converter.studio/developers), with the credits of your Converter Studio account. Nothing
has to be installed on your server: no ImageMagick with HEIC support, no FFmpeg, no LibreOffice.

## Requirements

- Grav 1.7 (1.7.50 or later) with or without the Admin plugin, or Grav 2 with or without Admin 2.
- PHP 8.1 or later (Grav 2 needs 8.3).
- A Converter Studio account and an API key. Free accounts get credits every day. Most photos and
  documents cost 1 credit; audio and video are priced by their length, and video also by its frame
  size. See [pricing](https://converter.studio/pricing).

## Installation

With the Grav Package Manager:

```bash
bin/gpm install converter-studio
```

In the Admin: **Plugins → Add**, search for "Converter Studio" and install it.

By hand: download the latest release, unzip it into `user/plugins` and rename the folder to
`converter-studio`.

## Setup

1. Make an API key on your [Converter Studio account page](https://converter.studio/account#api-keys).
2. In the Admin, open **Plugins → Converter Studio** and paste the key, or set it in the environment
   instead, which keeps it out of `user/config` (and out of a site kept in git):

   ```bash
   CONVERTER_STUDIO_API_KEY=cvs_live_…
   ```

   The key in the settings wins when both are set. A saved key is never shown again; leave the field
   as it is to keep it. To remove it, delete `api_key` from `user/config/plugins/converter-studio.yaml`.

3. Under **Convert when uploaded in the Admin**, switch on the kinds of files to convert. Nothing is
   converted until you do.
4. Check it: `bin/plugin converter-studio status` shows your plan and credits.

Without the Admin, put the settings in `user/config/plugins/converter-studio.yaml`; the defaults, with a
comment on each, are in the plugin's `converter-studio.yaml`:

```yaml
enabled: true
api_key: '' # or CONVERTER_STUDIO_API_KEY in the environment
rules:
  heic:
    upload: true
    to: jpg # jpg, webp or png
  video:
    upload: true
    to: mp4 # mp4 or webm
originals: keep # keep, beside or delete
max_credits: 20
```

## How it works

**On upload.** When a file of a switched-on kind is uploaded to a page in the Admin (Grav 1.7's
Admin, or Admin 2 on Grav 2, which also has the site-wide Media page), the plugin sends it to Converter
Studio and saves the result next to it under the same name with the new extension: `IMG_0042.heic`
becomes `IMG_0042.jpg`. A name that is taken gets a number (`IMG_0042-1.jpg`); nothing is overwritten.
The upload waits up to 40 seconds for its conversions (a setting); photos and documents usually take a
few seconds.

- **The original** is moved to `user/data/converter-studio/originals/`, which is not reachable from the
  web, so you keep it without publishing it. It can also stay next to the converted file, or be deleted.
- **Title, alt text and caption** set for the original (its `.meta.yaml` file) move to the converted
  file.
- **Links.** The editor inserts a file under the name it was uploaded with (`![](IMG_0042.heic)`). When
  the page is saved, such links point to the converted file (`![](IMG_0042.jpg)`), in the content and in
  the page's header (front matter).
- **A file that cannot be converted** (an unreadable file, no credits left…) stays as it was uploaded,
  and the uploader shows Converter Studio's explanation. Admin 2 words it as a failed upload ("Failed
  to upload IMG_0042.heic: Saved as uploaded. IMG_0042.heic was not converted: …"); the file is in the
  page all the same. Its automatic retries of that upload get the same answer, not a new conversion.
- **Only uploads in the Admin are converted**, never files that visitors send through forms: a
  conversion spends your credits.

**Slow conversions.** A conversion that outlasts the upload's wait (a long video) goes on at Converter
Studio, and the converted file is added when it is done, by Grav's scheduler. The scheduler runs when
`bin/grav scheduler` is in the server's crontab
([Grav's scheduler docs](https://learn.getgrav.org/17/advanced/scheduler)); without it, run:

```bash
bin/plugin converter-studio work
```

**Price limit.** A conversion that would cost more than 20 credits (a setting) waits for your approval
and costs nothing until then:

```bash
bin/plugin converter-studio status            # lists what waits, with its id
bin/plugin converter-studio confirm <id>      # or: confirm --all
```

## Converting existing media

Files that were on the site before, or added another way, convert with the `convert` command:

```bash
# Every iPhone photo on every page
bin/plugin converter-studio convert --all --format=heic

# One page's videos, to WebM
bin/plugin converter-studio convert --page=/blog/my-trip --format=video --to=webm

# What would be converted on the Media page (user/media), without converting
bin/plugin converter-studio convert --media --format=all --dry-run
```

`--format` takes `heic`, `raw`, `tiff`, `video`, `office`, `audio` or `all` (several with commas), and
defaults to the kinds switched on for uploads. A file that already has its converted file next to it
(`photo.heic` beside `photo.jpg`) is skipped, unless `--force`. Other options: `--originals`,
`--max-credits`, `--limit`, `--timeout`. Links in pages are updated the next time a page is saved in the
Admin; when you edit pages by hand, update them yourself.

## Settings

| Setting                                       | Default                 | What it does                                                          |
| --------------------------------------------- | ----------------------- | --------------------------------------------------------------------- |
| API key                                       | (none)                  | Your Converter Studio API key; `CONVERTER_STUDIO_API_KEY` when empty. |
| Convert when uploaded                         | all off                 | Per kind of file, and what it becomes.                                |
| The original file                             | keep in `user/data`     | Or leave it next to the converted file, or delete it.                 |
| Photo quality, longest side                   | balanced, keep the size | Photos are never enlarged.                                            |
| Keep the photo details (EXIF)                 | off                     | Camera, date and the like. The GPS location is never kept.            |
| Video size, video quality                     | 1080p, balanced         | Videos are never enlarged.                                            |
| MP3 quality                                   | 192 kbit/s              |                                                                       |
| Credits a file may cost without approval      | 20                      | More waits for `confirm`.                                             |
| Time an upload waits                          | 40 seconds              | Longer conversions finish in the background.                          |
| Finish slow conversions with Grav's scheduler | on                      |                                                                       |
| Update links when a page is saved             | on                      |                                                                       |

The plugin keeps its working state in `user/data/converter-studio/` (unfinished conversions, which
names were changed, kept originals). Finished conversions are forgotten after a week.

## Limits

- A file can be as large as your plan allows (`bin/plugin converter-studio status`); PHP's
  `upload_max_filesize` and `post_max_size` limit what the Admin can receive in the first place.
- Grav's scheduler, or the `work` command, is needed for conversions that take longer than the upload
  waits.
- Links are rewritten in the page that is saved, for files of that page's folder. Other pages that link
  to a converted file, and templates that name it, keep the old name.

## Privacy

The plugin sends the files it converts, with their names and sizes, to Converter Studio, and nothing
else about your site or its visitors. Converter Studio deletes each upload once it is converted and each
result after six hours. See the [privacy policy](https://converter.studio/privacy).

## Support

Questions and problems: [GitHub issues](https://github.com/tariksucu/grav-plugin-converter-studio/issues)
or [converter.studio/support](https://converter.studio/support).

## License

MIT, see [LICENSE](LICENSE).
