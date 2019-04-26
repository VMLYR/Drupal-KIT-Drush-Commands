<?php

namespace Drush\Commands\kit_drush;

/**
 * Inflection trait to wrap writing to output.
 */
trait WriteWrapperTrait {

  /**
   * Wrapper method to write information back to the user.
   *
   * @param string $message
   *   The message to write.
   * @param null|string $type
   *   The type of message.
   * @param bool $overwrite
   *   Whether this message should replace the current line.
   */
  protected function write($message, $type = NULL, $overwrite = FALSE) {
    if ($overwrite && !$this->io()->isVerbose()) {
      $this->write("\x0D");
    }

    switch ($type) {
      case 'error':
        $this->logger()->error($message);
        break;
      case 'notice':
        $this->logger()->notice($message);
        break;
      case 'success':
        $this->logger()->success($message);
        break;
      case 'warning':
        $this->logger()->warning($message);
        break;
      default:
        $this->io()->write(sprintf(' %s', $message));
    }
  }

}
