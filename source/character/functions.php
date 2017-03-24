<?php
function isCharacterSelected()
{
    return session('characterName') !== null;
}

function initializeCharacterData($character = null)
{
    $characters = getCharactersForUser(getCurrentUsername());
    if (count($characters) === 0) {
        router('/character/new');
        return null;
    }
    $activeCharacter = array_values($characters)[0];
    if ($character) {
        $key = md5($character);
        $activeCharacter = isset($characters[$key]) ? $characters[$key] : $activeCharacter;
    }
    $activeCharacter['inventory'] = getEquipmentForCharacter($activeCharacter['name']);

    navigation(_('Select character'), '/character/view');
    navigation(_('Logout'), '/logout');

    activateNavigation('/character/select');
    return [$characters, $activeCharacter];
}

function askToDeleteCharacter($character = null)
{
    if (!isLoggedIn()) {
        return event('http.403');
    }
    session('characterName', null);
    list($characters, $activeCharacter) = initializeCharacterData($character);
    $data = [
        'characters' => $characters,
        'activeCharacter' => $activeCharacter,
        'equipmentSlots' => config('equipmentSlots'),
        'isDeletion' => true
    ];
    session('characterToDelete', $activeCharacter['name']);
    echo render('selectCharacter', $data);
}

function deleteCharacter()
{
    if (!isLoggedIn()) {
        return event('http.403');
    }
    session('characterName', null);
    $characterName = session('characterToDelete');

    if (!$characterName) {
        router('/character/new');
        return;
    }
    $db = getDb();
    $characterName = mysqli_real_escape_string($db, $characterName);
    $userId = getCurrentUserId();
    $sql = "DELETE FROM characters WHERE name='" . $characterName . "' AND userId  = " . $userId;
    mysqli_query($db, $sql);
    session('characterToDelete', null);
    redirect('/character/new');

}

function selectCharacter($character)
{
    if (!isLoggedIn()) {
        return event('http.403');
    }
    session('characterToDelete', null);
    session('characterName', null);
    if (!getCharacterForUser($character, getCurrentUsername())) {
        redirect('/');
        return;
    }
    session('characterName', $character);
    redirect('/');
}

function viewCharacter($character = null)
{
    if (!isLoggedIn()) {
        return event('http.403');
    }
    session('characterToDelete', null);
    session('characterName', null);
    list($characters, $activeCharacter) = initializeCharacterData($character);
    $data = [
        'characters' => $characters,
        'activeCharacter' => $activeCharacter,
        'equipmentSlots' => config('equipmentSlots'),
        'isDeletion' => false
    ];

    echo render('selectCharacter', $data);
}

function newCharacter()
{
    if (!isLoggedIn()) {
        return event('http.403');
    }
    session('characterToDelete', null);
    session('characterName', null);
    navigation(_('Select character'), '/character/view');
    navigation(_('Logout'), '/logout');

    activateNavigation('/character/select');

    $characters = getCharactersForUser(getCurrentUsername());


    $characterName = '';
    $characterClass = '';
    $characterGender = '';
    $errors = [];


    if (isPost()) {
        $characterName = filter_input(INPUT_POST, 'characterName', FILTER_SANITIZE_STRING);
        $characterClass = filter_input(INPUT_POST, 'class', FILTER_SANITIZE_STRING);
        $characterGender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
        $nameErrors = validateCharacterName($characterName);
        $classErrors = validateCharacterClass($characterClass);
        $genderErrors = validateCharacterGender($characterGender);
        $errors = array_merge($nameErrors, $classErrors, $genderErrors);
        if (count($errors) === 0) {
            if ($characterId = createCharacter(getCurrentUserId(), $characterName, $characterClass, $characterGender)) {
                $newCharacter = [
                    'id' => $characterId,
                    'name' => $characterName,
                    'class' => $characterClass,
                    'gender' => $characterGender
                ];
                event('game.newCharacter', $newCharacter);
                redirect('/character/view/' . $characterName);
            }
            $errors[] = _('Failed to create character');
        }
    }
    $newCharacter = [
        'name' => $characterName,
        'class' => $characterClass,
        'gender' => $characterGender
    ];
    $data = [
        'characters' => $characters,
        'newCharacter' => $newCharacter,
        'errors' => $errors
    ];

    echo render('newCharacter', $data);
}

function createCharacter($userId, $characterName, $characterClass, $characterGender)
{
    $db = getDb();
    $characterName = mysqli_real_escape_string($db, $characterName);
    $characterClass = mysqli_real_escape_string($db, $characterClass);
    $characterGender = (int)($characterGender === 'male');
    $sql = "INSERT INTO characters(name,userId,class,gender) 
    VALUES ('" . $characterName . "'," . $userId . ",'" . $characterClass . "','" . $characterGender . "')";
    $result = mysqli_query($db, $sql);
    if (!$result) {
        trigger_error(mysqli_error($db), E_USER_ERROR);
        return null;
    }
    return mysqli_insert_id($db);
}

function validateCharacterGender($gender)
{
    $errors = [];
    $availableGenders = ['male', 'female'];

    if (!(bool)$gender) {
        $errors[] = _('Please select a gender');
        return $errors;
    }
    if (!in_array($gender, $availableGenders)) {
        $errors[] = _('Invalid gender selected');
    }
    return $errors;
}

function validateCharacterClass($characterClass)
{
    $errors = [];
    $availableClasses = ['warrior', 'ranger', 'mage'];
    if (!(bool)$characterClass) {
        $errors[] = _('Please select a class');
        return $errors;
    }
    if (!in_array($characterClass, $availableClasses)) {
        $errors[] = _('Invalid class selected');
    }

    return $errors;
}

function validateCharacterName($characterName)
{
    $errors = [];
    $minLength = 3;
    $maxLength = 32;
    $blacklist = getBadWords();

    if (!(bool)$characterName) {
        $errors[] = _('Character name is empty');
        return $errors;
    }
    if (preg_match('~\W+~', $characterName)) {
        $errors[] = _('Character name contain non word characters');
    }
    if (mb_strlen($characterName) < $minLength) {
        $errors[] = sprintf(_('Character name is too short, %d characters are at least required'), $minLength);
    }
    if (mb_strlen($characterName) >= $maxLength) {
        $errors[] = sprintf(_('Character name is too long, maximum %d characters'), $maxLength);
    }
    if (in_array($characterName, $blacklist)) {
        $errors[] = _("Selected name is not allowed to use");
    }
    if (characterNameExists($characterName)) {
        $errors[] = sprintf(_("The character name %s already exists"), $characterName);
    }
    return $errors;
}

function characterNameExists($characterName)
{
    $db = getDb();
    $characterName = mysqli_real_escape_string($db, $characterName);
    $sql = "SELECT 1 FROM characters WHERE name = '" . $characterName . "'";
    $result = mysqli_query($db, $sql);
    if (!$result) {
        return false;
    }
    return (bool)$result->num_rows;
}

function getCharacterForUser($characterName, $username)
{
    $db = getDb();
    $username = mysqli_real_escape_string($db, $username);
    $characterName = mysqli_real_escape_string($db, $characterName);
    $sql = "SELECT name,class,gender FROM characters 
      INNER JOIN users ON(characters.userId = users.userId) 
      WHERE username = '" . $username . "' AND name='" . $characterName . "' LIMIT 1";


    $result = mysqli_query($db, $sql);
    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc();
    if(!$row){
        return $row;
    }

    $row['gender'] = (int)$row['gender'] === 1 ? 'male' : 'female';
    return $row;
}

function getCharactersForUser($username)
{
    $db = getDb();
    $username = mysqli_real_escape_string($db, $username);
    $sql = "SELECT name,class,gender FROM characters 
      INNER JOIN users ON(characters.userId = users.userId) 
      WHERE username = '" . $username . "' ORDER BY characters.lastAction DESC";

    $characters = [];

    $result = mysqli_query($db, $sql);
    if (!$result) {
        return $characters;
    }

    while ($row = $result->fetch_assoc()) {
        $characterKey = md5($row['name']);
        $row['gender'] = (int)$row['gender'] === 1 ? 'male' : 'female';
        $characters[$characterKey] = $row;
    }
    return $characters;
}