<?php


namespace D9ify\Exceptions;

class ComposerInstallException extends D9ifyExceptionBase implements D9ifyExceptionInterface
{

    protected static string $MESSAGE_TEXT = "Composer experienced a problem with the install.";
}
