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
 * @group markup
 */
class PhabricatorRemarkupRuleMention
  extends PhutilRemarkupRule {

  const KEY_RULE_MENTION  = 'rule.mention';
  const KEY_MENTIONED     = 'phabricator.mentioned-user-phids';

  public function apply($text) {

    // NOTE: Negative lookahead for period prevents us from picking up email
    // addresses, while allowing constructs like "@tomo, lol". The negative
    // lookbehind for a word character prevents us from matching "mail@lists"
    // while allowing "@tomo/@mroch". The negative lookahead prevents us from
    // matching "@joe.com" while allowing us to match "hey, @joe.".
    $regexp = '/(?<!\w)@([a-zA-Z0-9]+)\b(?![.]\w)/';

    return preg_replace_callback(
      $regexp,
      array($this, 'markupMention'),
      $text);
  }

  private function markupMention($matches) {
    $username = strtolower($matches[1]);
    $engine = $this->getEngine();

    $token = $engine->storeText('');

    $metadata_key = self::KEY_RULE_MENTION;
    $metadata = $engine->getTextMetadata($metadata_key, array());
    if (empty($metadata[$username])) {
      $metadata[$username] = array();
    }
    $metadata[$username][] = $token;
    $engine->setTextMetadata($metadata_key, $metadata);

    return $token;
  }

  public function didMarkupText() {
    $engine = $this->getEngine();

    $metadata_key = self::KEY_RULE_MENTION;
    $metadata = $engine->getTextMetadata($metadata_key, array());
    if (empty($metadata)) {
      // No mentions, or we already processed them.
      return;
    }

    $usernames = array_keys($metadata);
    $user_table = new PhabricatorUser();
    $real_user_names = queryfx_all(
      $user_table->establishConnection('r'),
      'SELECT username, phid, realName FROM %T WHERE username IN (%Ls)',
      $user_table->getTableName(),
      $usernames);

    $actual_users = array();

    $mentioned_key = self::KEY_MENTIONED;
    $mentioned = $engine->getTextMetadata($mentioned_key, array());
    foreach ($real_user_names as $row) {
      $actual_users[strtolower($row['username'])] = $row;
      $mentioned[$row['phid']] = $row['phid'];
    }

    $engine->setTextMetadata($mentioned_key, $mentioned);

    foreach ($metadata as $username => $tokens) {
      $exists = isset($actual_users[$username]);
      $class = $exists
        ? 'phabricator-remarkup-mention-exists'
        : 'phabricator-remarkup-mention-unknown';

      if ($exists) {
        $tag = phutil_render_tag(
          'a',
          array(
            'class'   => $class,
            'href'    => '/p/'.$username.'/',
            'target'  => '_blank',
            'title'   => $actual_users[$username]['realName'],
          ),
          phutil_escape_html('@'.$username));
      } else {
        $tag = phutil_render_tag(
          'span',
          array(
            'class' => $class,
          ),
          phutil_escape_html('@'.$username));
      }
      foreach ($tokens as $token) {
        $engine->overwriteStoredText($token, $tag);
      }
    }

    // Don't re-process these mentions.
    $engine->setTextMetadata($metadata_key, array());
  }

}
