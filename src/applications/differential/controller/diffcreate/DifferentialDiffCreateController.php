<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class DifferentialDiffCreateController extends DifferentialController {

  public function processRequest() {

    $request = $this->getRequest();

    if ($request->isFormPost()) {
      $parser = new ArcanistDiffParser();
      $diff = $request->getStr('diff');
      $changes = $parser->parseDiff($diff);
      $diff = DifferentialDiff::newFromRawChanges($changes);

      $diff->setLintStatus(DifferentialLintStatus::LINT_SKIP);
      $diff->setUnitStatus(DifferentialLintStatus::LINT_SKIP);

      $diff->setAuthorPHID($request->getUser()->getPHID());
      $diff->setCreationMethod('web');
      $diff->save();

      return id(new AphrontRedirectResponse())
        ->setURI('/differential/diff/'.$diff->getID().'/');
    }

    $form = new AphrontFormView();
    $form
      ->setAction('/differential/diff/create/')
      ->setUser($request->getUser())
      ->appendChild(
        '<p class="aphront-form-instructions">The best way to create a '.
        'Differential diff is by using <strong>Arcanist</strong>, but you '.
        'can also just paste a diff (e.g., from <tt>svn diff</tt> or '.
        '<tt>git diff</tt>) into this box if you really want.</p>')
      ->appendChild(
        id(new AphrontFormTextAreaControl())
          ->setLabel('Raw Diff')
          ->setName('diff')
          ->setHeight(AphrontFormTextAreaControl::HEIGHT_VERY_TALL))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue("Create Diff \xC2\xBB"));

    $panel = new AphrontPanelView();
    $panel->setHeader('Create New Diff');
    $panel->appendChild($form);
    $panel->setWidth(AphrontPanelView::WIDTH_FORM);

    return $this->buildStandardPageResponse(
      $panel,
      array(
        'title' => 'Create Diff',
        'tab' => 'create',
      ));
  }

}