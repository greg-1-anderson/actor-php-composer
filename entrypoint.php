<?php

$github_token = getenv('GITHUB_API_TOKEN');
if ($github_token) {
    shell_exec("echo -e \"machine github.com\n  login x-access-token\n  password $github_token\" >> ~/.netrc");
    shell_exec("composer config -g http-basic.github.com x-access-token $github_token");
}

function path_join($base, $path) {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function runCommand($cmd) {
    echo "Exec: $cmd\n";
    exec($cmd, $output, $return);
    if ($return) {
        var_dump($output);
        throw new Exception("Exception running: $cmd\n\n$output");
    }
    return $output;
}

$actor_id = getenv('ACTOR_ID');
$git_sha = getenv('GIT_SHA');
$testing = getenv('DEPENDENCIES_ENV') === 'test';
$commit_message_prefix = getenv('SETTING_COMMIT_MESSAGE_PREFIX');

$dependencies_schema = json_decode(getenv('DEPENDENCIES'), true);
$dependencies = $dependencies_schema['dependencies'];

foreach ($dependencies as $dependency) {
    $composer_dir = path_join('/repo', $dependency['path']);
    $composer_json_path = path_join($composer_dir, 'composer.json');
    if (!file_exists($composer_json_path)) {
        throw new Exception("$composer_json_path does not exist! A composer.json file is required.");
    }
    $composer_json = json_decode(file_get_contents($composer_json_path), true);
    $composer_require = array_key_exists('require', $composer_json) ? $composer_json['require'] : array();
    $composer_require_dev = array_key_exists('require-dev', $composer_json) ? $composer_json['require-dev'] : array();

    // if they didn't have a composer.lock to start with, then we won't commit one
    $composer_lock_path = path_join($composer_dir, 'composer.lock');
    $composer_lock_existed = file_exists($composer_lock_path);

    $highest_available = $dependency['available'][0];
    $name = $dependency['name'];
    $installed_version = $dependency['installed']['version'];
    $version_to_install = $highest_available['version'];

    $branch_name = "$name-$version_to_install-#$actor_id";

    runCommand("git checkout $git_sha");
    runCommand("git checkout -b $branch_name");

    runCommand("cd $composer_dir && composer install --ignore-platform-reqs --no-scripts");

    if (array_key_exists($name, $composer_require)) {
        runCommand("cd $composer_dir && composer require --ignore-platform-reqs --no-scripts $name:$version_to_install");
    } else if (array_key_exists($name, $composer_require_dev)) {
        runCommand("cd $composer_dir && composer require --dev --ignore-platform-reqs --no-scripts $name:$version_to_install");
    } else if ($composer_lock_existed) {
        // Update indirect dependency in composer.lock
        // Add the dependency directly to composer.json and updates composer.lock, then revert composer.json and correct the composer.lock hash
        // Workaround for https://github.com/composer/composer/issues/3387
        runCommand("cd $composer_dir && composer require --ignore-platform-reqs --no-scripts $name:$version_to_install && git checkout -- $composer_json_path && composer update --lock");
    } else {
        throw new Exception("Didn't find $name in $composer_json_path require or require-dev.");
    }

    if ($composer_lock_existed) {
        runCommand("git add $composer_lock_path");
    } else {
        runCommand("rm $composer_lock_path");
    }

    runCommand("git add $composer_json_path");
    $message = "{$commit_message_prefix}Update $name from $installed_version to $version_to_install";
    runCommand("git commit -m '$message'");

    if (!$testing) {
        runCommand("git push --set-upstream origin $branch_name");
    }

    $pr_body = "$name has been updated to $version_to_install by dependencies.io.";
    foreach ($dependency['available'] as $available) {
        $version = $available['version'];
        $content = $available['content'] ?? '_No content found._';
        $pr_body .= "\n\n## $version\n\n$content";
    }

    if (!$testing) {
        runCommand("pullrequest --branch " . escapeshellarg($branch_name) . " --title " . escapeshellarg($message) . " --body " . escapeshellarg($pr_body));
    }

    // tell dependencies.io that this one got updated successfully
    $schema_output = json_encode(array('dependencies' => array($dependency)));
    echo("BEGIN_DEPENDENCIES_SCHEMA_OUTPUT>$schema_output<END_DEPENDENCIES_SCHEMA_OUTPUT\n");
}
