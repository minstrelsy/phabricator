<?php

abstract class DiffusionSSHWorkflow extends PhabricatorSSHWorkflow {

  private $args;
  private $repository;
  private $hasWriteAccess;

  public function getRepository() {
    return $this->repository;
  }

  public function getArgs() {
    return $this->args;
  }

  abstract protected function getRequestPath();
  abstract protected function executeRepositoryOperations(
    PhabricatorRepository $repository);

  protected function writeError($message) {
    $this->getErrorChannel()->write($message);
    return $this;
  }

  final public function execute(PhutilArgumentParser $args) {
    $this->args = $args;

    try {
      $repository = $this->loadRepository();
      $this->repository = $repository;
      return $this->executeRepositoryOperations($repository);
    } catch (Exception $ex) {
      $this->writeError(get_class($ex).': '.$ex->getMessage());
      return 1;
    }
  }

  private function loadRepository() {
    $viewer = $this->getUser();
    $path = $this->getRequestPath();

    $regex = '@^/?diffusion/(?P<callsign>[A-Z]+)(?:/|$)@';
    $matches = null;
    if (!preg_match($regex, $path, $matches)) {
      throw new Exception(
        pht(
          'Unrecognized repository path "%s". Expected a path like '.
          '"%s".',
          $path,
          "/diffusion/X/"));
    }

    $callsign = $matches[1];
    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->withCallsigns(array($callsign))
      ->executeOne();

    if (!$repository) {
      throw new Exception(
        pht('No repository "%s" exists!', $callsign));
    }

    switch ($repository->getServeOverSSH()) {
      case PhabricatorRepository::SERVE_READONLY:
      case PhabricatorRepository::SERVE_READWRITE:
        // If we have read or read/write access, proceed for now. We will
        // check write access when the user actually issues a write command.
        break;
      case PhabricatorRepository::SERVE_OFF:
      default:
        throw new Exception(
          pht('This repository is not available over SSH.'));
    }

    return $repository;
  }

  protected function requireWriteAccess() {
    if ($this->hasWriteAccess === true) {
      return;
    }

    $repository = $this->getRepository();
    $viewer = $this->getUser();

    switch ($repository->getServeOverSSH()) {
      case PhabricatorRepository::SERVE_READONLY:
        throw new Exception(
          pht('This repository is read-only over SSH.'));
        break;
      case PhabricatorRepository::SERVE_READWRITE:
        $can_push = PhabricatorPolicyFilter::hasCapability(
          $viewer,
          $repository,
          DiffusionCapabilityPush::CAPABILITY);
        if (!$can_push) {
          throw new Exception(
            pht('You do not have permission to push to this repository.'));
        }
        break;
      case PhabricatorRepository::SERVE_OFF:
      default:
        // This shouldn't be reachable because we don't get this far if the
        // repository isn't enabled, but kick them out anyway.
        throw new Exception(
          pht('This repository is not available over SSH.'));
    }

    $this->hasWriteAccess = true;
    return $this->hasWriteAccess;
  }


}