# The recorder's runtime: Node, Playwright's Chromium with every system
# library it needs, and ffmpeg for the .webm -> .mp4 conversion, and
# poppler-utils (pdftoppm, pdfinfo) to save each generated PDF as images. Used by
# record.sh, so nothing has to be installed on the host (WSL) itself.
FROM mcr.microsoft.com/playwright:v1.65.1-noble

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg poppler-utils fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /recorder
