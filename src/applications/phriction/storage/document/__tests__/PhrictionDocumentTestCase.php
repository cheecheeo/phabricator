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

/**
 * @group phriction
 */
class PhrictionDocumentTestCase extends PhabricatorTestCase {

  public function testSlugNormalization() {
    $slugs = array(
      ''                  => '/',
      '/'                 => '/',
      '//'                => '/',
      '/derp/'            => 'derp/',
      'derp'              => 'derp/',
      'derp//derp'        => 'derp/derp/',
      'DERP//DERP'        => 'derp/derp/',
      'a B c'             => 'a_b_c/',
    );

    foreach ($slugs as $slug => $normal) {
      $this->assertEqual(
        $normal,
        PhrictionDocument::normalizeSlug($slug),
        "Normalization of '{$slug}'");
    }
  }

  public function testSlugAncestry() {
    $slugs = array(
      '/'                   => array(),
      'pokemon/'            => array('/'),
      'pokemon/squirtle/'   => array('/', 'pokemon/'),
    );

    foreach ($slugs as $slug => $ancestry) {
      $this->assertEqual(
        $ancestry,
        PhrictionDocument::getSlugAncestry($slug),
        "Ancestry of '{$slug}'");
    }
  }

  public function testSlugDepth() {
    $slugs = array(
      '/'       => 0,
      'a/'      => 1,
      'a/b/'    => 2,
      'a////b/' => 2,
    );

    foreach ($slugs as $slug => $depth) {
      $this->assertEqual(
        $depth,
        PhrictionDocument::getSlugDepth($slug),
        "Depth of '{$slug}'");
    }
  }

}
