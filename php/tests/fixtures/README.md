# Fixtures

`image.png` is a visible 32 × 32 image. `video-header.mp4` is a small ISO-BMFF header used to test file signatures and size limits; it is not a playable video. Tests pad the header when a larger payload is needed.

The `sample.*` files cover GIF, short MP3 frames, PCM WAV, ZIP, GZIP, and a one-pixel grayscale TIFF. They test content detection, extension matching, and byte-preserving downloads.
