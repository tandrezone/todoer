<?php
/**
 * The group page: who is in it, admin tools, and joining or leaving.
 *
 * The member list, the invite code and which panels are visible are all rendered by
 * assets/js/group.js from /api/group -- including the admin panel, which is absent rather than
 * disabled for a member, because the API does not send a member the invite code at all.
 *
 * @var callable $e
 * @var \App\Http\UrlGenerator $url
 * @var \App\Domain\User\User $user
 * @var \App\Domain\Group\Group $group
 * @var callable $partial
 */
?>
<?= $partial('partials/topnav', [
    'user' => $user,
    'group' => $group,
    'activeNav' => 'group',
    'showPushButton' => false,
]) ?>

<main class="group-page">
  <h1 id="group-title"><?= $e($group->name) ?></h1>
  <p class="hint">
    Your group is the whole game: you see each other's tasks, you compete on the same leaderboard, and prizes
    are awarded inside the group. People outside it can't see any of this &mdash; and you can't see theirs.
  </p>

  <div id="group-message" class="group-message" hidden></div>

  <section class="group-card">
    <h2>Members <span class="board-count" id="member-count"></span></h2>
    <ul class="member-list" id="member-list"></ul>
  </section>

  <section class="group-card" id="admin-tools" hidden>
    <h2>Add someone</h2>

    <form class="group-form" id="add-member-form">
      <label>They already have a Todoer account
        <input type="text" name="username" placeholder="their username" autocomplete="off" maxlength="40">
      </label>
      <button type="submit">Add to group</button>
      <p class="hint">They'll move into this group. Their old tasks and points stay behind in their previous group.</p>
    </form>

    <form class="group-form" id="create-member-form">
      <label>Or create an account for them
        <input type="text" name="username" placeholder="username" autocomplete="off" maxlength="40">
      </label>
      <label>Their password
        <input type="password" name="password" placeholder="at least 8 characters" autocomplete="new-password">
      </label>
      <button type="submit">Create &amp; add</button>
      <p class="hint">Hand them the password afterwards &mdash; pick something you can pass on.</p>
    </form>

    <h2>Invite code</h2>
    <p class="hint">Anyone with this code can join the group and see everything in it. Roll it if it gets out.</p>
    <div class="invite-row">
      <code id="invite-code">&mdash;</code>
      <button type="button" id="regenerate-code">Generate a new code</button>
    </div>

    <h2>Group name</h2>
    <form class="group-form" id="rename-form">
      <label>Name
        <input type="text" name="name" maxlength="60" value="<?= $e($group->name) ?>">
      </label>
      <button type="submit">Rename</button>
    </form>
  </section>

  <section class="group-card">
    <h2>Join another group</h2>
    <form class="group-form" id="join-form">
      <label>Invite code
        <input type="text" name="invite_code" placeholder="e.g. K7PQ2XVM" autocomplete="off" maxlength="16">
      </label>
      <button type="submit">Join</button>
      <p class="hint">You can only be in one group at a time, so joining moves you out of this one. Your tasks and
        points stay where they were earned.</p>
    </form>
    <button type="button" id="leave-group" class="danger-btn">Leave this group</button>
  </section>
</main>
