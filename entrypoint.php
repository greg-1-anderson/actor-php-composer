<?php

function path_join($base, $path) {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function runCommand($cmd) {
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

$dependencies_schema = json_decode(getenv('DEPENDENCIES'), true);
$dependencies = $dependencies_schema['dependencies'];

$composer_json = json_decode(file_get_contents(path_join(path_join('/repo', $argv[1]), 'composer.json')), true);
$composer_require = array_key_exists('require', $composer_json) ? $composer_json['require'] : array();
$composer_require_dev = array_key_exists('require-dev', $composer_json) ? $composer_json['require-dev'] : array();


foreach ($dependencies as $dependency) {
    $highest_available = $dependency['available'][0];
    $name = $dependency['name'];
    $installed_version = $dependency['installed']['version'];
    $version_to_install = $highest_available['version'];

    $branch_name = "$name-$version_to_install-#$actor_id";

    runCommand("git checkout $git_sha");
    runCommand("git checkout -b $branch_name");

    if (array_key_exists($name, $composer_require)) {
        runCommand("composer require --ignore-platform-reqs --no-scripts $name:$version_to_install");
    } else if (array_key_exists($name, $composer_require_dev)) {
        runCommand("composer require --dev --ignore-platform-reqs --no-scripts $name:$version_to_install");
    } else {
        throw new Exception("Didn't find $name in composer.json require or require-dev.");
    }

    runCommand("git add composer.json composer.lock");
    $message = "Update $name from $installed_version to $version_to_install";
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
