# The `gd` extension list, and why it is a list

`gd` as `spc craft` builds it has almost nothing in it. StaticPHP follows a
"minimal dependency principle": `bin/spc build gd` gets the extension and not the
graphics libraries it usually leans on, so an image the library would otherwise
handle is refused.

The libraries that matter are asked for by name:

```
freetype   text on an image, and the metrics for it
libjpeg    JPEG
libavif    AVIF
libwebp    WebP
```

**The one that matters here is `libjpeg`.** Without it a static build cannot read
or write a JPEG at all, and a JPEG is not an edge case — it is what comes out of
every phone. `ext-gd` being present would make `composer check-platform-reqs`
pass while the artifact silently refused the commonest image format there is,
which is the failure mode this note exists to prevent.

## Where each platform gets them

Three of the four platforms take the interpreter upstream publishes, and upstream
builds theirs with the libraries above. The fourth is compiled here, and asks for
them by name:

```yaml
build-options:
  with-libs: "freetype,libjpeg,libavif,libwebp"
```

`craft-windows.yml` is that file. `tests/Unit/craft.php` holds it and fails when the
list stops being there.

## What to assert

`ext-gd` being present says nothing about the libraries behind it: the function is
compiled in either way, and only reading a JPEG tells the difference. So the
smoke test does not ask whether `gd` is loaded — it asks the interpreter to read a
known JPEG and reports whether it could.

`tools/extension-probe.php` is what asks. It is appended to the same interpreter the
released binary is made of, so what it reports is what the binary carries.

`ext-imagick` is a different matter: it is optional in both libraries, and an SVG
raises and says what to do about it when the extension is not there. The pipeline
asserts its presence on the platforms that have it and its absence on the one that
cannot, so a build that quietly gained it — or quietly lost it — is noticed.
