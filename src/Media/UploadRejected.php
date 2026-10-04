<?php

namespace Nevela\Laravel\Media;

use RuntimeException;

/** An upload the field's profile refuses, with a message fit to show the person who sent it. */
final class UploadRejected extends RuntimeException {}
