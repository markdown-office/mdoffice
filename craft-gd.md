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

Asked for as:

```
spc build gd --with-libs=freetype,libjpeg,libavif,libwebp
```

or, in `craft.yml`, through the same option. This is not yet wired into the
pipeline: `craft.yml` names `gd` and nothing else, and the spike build that proved
the macOS executable converts a document used `craft.yml` as it stands. The full
image round trip — a JPEG in, a JPEG out — is the check that would settle it, and
belongs with the pipeline rather than with this file.

## What to assert

Per platform, in the smoke test:

- `extension_loaded('gd')` — true everywhere.
- `imagecreatefromjpeg()` succeeds on a known JPEG. Not `function_exists`: the
  function is compiled in either way, and it is the *library* behind it that is
  missing.
- `extension_loaded('imagick')` — true on Linux and macOS, **false on Windows**.

That last one is asserted rather than assumed. It is a known, deliberate gap, and
a build that quietly gained it would be a surprise in one direction and an absence
in the other; either way CI should be the place it is noticed.