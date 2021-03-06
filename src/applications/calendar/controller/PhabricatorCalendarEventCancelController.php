<?php

final class PhabricatorCalendarEventCancelController
  extends PhabricatorCalendarController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');

    // Just check CAN_VIEW first. Then we'll check if this is an import so
    // we can raise a better error.
    $event = id(new PhabricatorCalendarEventQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->executeOne();
    if (!$event) {
      return new Aphront404Response();
    }

    $response = $this->newImportedEventResponse($event);
    if ($response) {
      return $response;
    }

    // Now that we've done the import check, check for CAN_EDIT.
    PhabricatorPolicyFilter::requireCapability(
      $viewer,
      $event,
      PhabricatorPolicyCapability::CAN_EDIT);

    $cancel_uri = $event->getURI();

    $is_parent = $event->isParentEvent();
    $is_child = $event->isChildEvent();

    $is_cancelled = $event->getIsCancelled();
    $is_recurring = $event->getIsRecurring();

    $validation_exception = null;
    if ($request->isFormPost()) {

      $targets = array($event);
      if ($is_recurring) {
        $mode = $request->getStr('mode');
        $is_future = ($mode == 'future');

        // We need to fork the event if we're cancelling just the parent, or
        // are cancelling a child and all future events.
        $must_fork = ($is_child && $is_future) ||
                     ($is_parent && !$is_future);
        if ($must_fork) {
          $fork_target = $event->loadForkTarget($viewer);
          if ($fork_target) {
            $xactions = array();

            $xaction = id(new PhabricatorCalendarEventTransaction())
              ->setTransactionType(
                PhabricatorCalendarEventForkTransaction::TRANSACTIONTYPE)
              ->setNewValue(true);

            $editor = id(new PhabricatorCalendarEventEditor())
              ->setActor($viewer)
              ->setContentSourceFromRequest($request)
              ->setContinueOnNoEffect(true)
              ->setContinueOnMissingFields(true);

            $editor->applyTransactions($fork_target, array($xaction));
          }
        }

        if ($is_future) {
          $future = $event->loadFutureEvents($viewer);
          foreach ($future as $future_event) {
            $targets[] = $future_event;
          }
        }
      }

      foreach ($targets as $target) {
        $xactions = array();

        $xaction = id(new PhabricatorCalendarEventTransaction())
          ->setTransactionType(
            PhabricatorCalendarEventCancelTransaction::TRANSACTIONTYPE)
          ->setNewValue(!$is_cancelled);

        $editor = id(new PhabricatorCalendarEventEditor())
          ->setActor($viewer)
          ->setContentSourceFromRequest($request)
          ->setContinueOnNoEffect(true)
          ->setContinueOnMissingFields(true);

        try {
          $editor->applyTransactions($target, array($xaction));
        } catch (PhabricatorApplicationTransactionValidationException $ex) {
          $validation_exception = $ex;
          break;
        }

      }

      if (!$validation_exception) {
        return id(new AphrontRedirectResponse())->setURI($cancel_uri);
      }
    }

    if ($is_cancelled) {
      $title = pht('Reinstate Event');
      if ($is_recurring) {
        $body = pht(
          'This event is part of a series. Which events do you want to '.
          'reinstate?');
        $show_control = true;
      } else {
        $body = pht('Reinstate this event?');
        $show_control = false;
      }
      $submit = pht('Reinstate Event');
    } else {
      $title = pht('Cancel Event');
      if ($is_recurring) {
        $body = pht(
          'This event is part of a series. Which events do you want to '.
          'cancel?');
        $show_control = true;
      } else {
        $body = pht('Cancel this event?');
        $show_control = false;
      }
      $submit = pht('Cancel Event');
    }

    $dialog = $this->newDialog()
      ->setTitle($title)
      ->setValidationException($validation_exception)
      ->appendParagraph($body)
      ->addCancelButton($cancel_uri, pht('Back'))
      ->addSubmitButton($submit);

    if ($show_control) {
      $form = id(new AphrontFormView())
        ->setViewer($viewer)
        ->appendControl(
          id(new AphrontFormSelectControl())
            ->setLabel(pht('Cancel Events'))
            ->setName('mode')
            ->setOptions(
              array(
                'this' => pht('Only This Event'),
                'future' => pht('All Future Events'),
              )));
      $dialog->appendForm($form);
    }

    return $dialog;
  }
}
