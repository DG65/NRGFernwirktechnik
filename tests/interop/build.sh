#!/bin/sh
# Baut lib60870 (nur fuer den Test, GPL, nicht Teil des Moduls) und den Test-Client.
# Aufruf: tests/interop/build.sh <Verzeichnis-mit-lib60870-Klon>
# Auf Apple Silicon in lib60870-C/make/target_system.mk "-arch i386" durch "-arch arm64" ersetzen.
set -e
L="${1:?Pfad zum lib60870-Klon}/lib60870-C"
here="$(cd "$(dirname "$0")" && pwd)"
make -C "$L" >/dev/null
cc -o "$here/cs104_master" "$here/cs104_master.c" -I"$L/src/inc/api" -I"$L/src/hal/inc" -I"$L/src/inc/internal" "$L/build/liblib60870.a" -lpthread -lm
echo "gebaut: $here/cs104_master"
