<?php

function path_join($base, $path) {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function runCommand($cmd) {
    exec($cmd, $output, $return);
    if ($return) {
        throw new Exception("Exception running: $cmd\n\n$output");
    }
    return $output;
}

$actor_id = getenv('ACTOR_ID');
$git_sha = getenv('GIT_SHA');
$base_branch = getenv('GIT_BRANCH');
$github_api_token = getenv('GITHUB_API_TOKEN');
$github_repo_full_name = getenv('GITHUB_REPO_FULL_NAME');
$testing = getenv('DEPENDENCIES_ENV') === 'test';

// user settings
$pr_labels = getenv('SETTING_PR_LABELS');
$pr_labels = $pr_labels ? json_decode($pr_labels, true) : null;
$pr_assignees = getenv('SETTING_PR_ASSIGNEES');
$pr_assignees = $pr_assignees ? json_decode($pr_assignees, true) : null;
$pr_milestone = getenv('SETTING_PR_MILESTONE');
$pr_milestone = $pr_milestone ? (int) $pr_milestone : null;

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

    $pr_data = array(
        'title' => $message,
        'body' => $pr_body,
        'base' => $base_branch,
        'head' => $branch_name
    );

    $options = array(
      'http' => array(
        'method'  => 'POST',
        'content' => json_encode( $pr_data ),
        'header'=>  "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n" .
                    "Authorization: token $github_api_token\r\n" .
                    "User-Agent: dependencies.io actor-php-composer\r\n"
        )
    );

    $url = "https://api.github.com/repos/$github_repo_full_name/pulls";

    if (!$testing) {
        $context  = stream_context_create( $options );
        $result = file_get_contents( $url, false, $context );
        if ($result === FALSE) {
            throw new Exception('Failed to create pull request.');
        }
        $response = json_decode($result, true);

        // if the user added labels, assignees, or milestone
        // have to do that in a separate patch to the issue endpoint
        $issue_url = $response['issue_url'];
        $issue_fields = array();
        if ($pr_labels !== null) {
            $issue_fields['labels'] = $pr_labels;
        }
        if ($pr_assignees !== null) {
            $issue_fields['labels'] = $pr_assignees;
        }
        if ($pr_milestone !== null) {
            $issue_fields['milestone'] = $pr_milestone;
        }
        if (count($issue_fields) > 0) {
            $options = array(
              'http' => array(
                'method'  => 'PATCH',
                'content' => json_encode( $issue_fields ),
                'header'=>  "Content-Type: application/json\r\n" .
                            "Accept: application/json\r\n" .
                            "Authorization: token $github_api_token\r\n" .
                            "User-Agent: dependencies.io actor-php-composer\r\n"
                )
            );
            $context  = stream_context_create( $options );
            $result = file_get_contents( $issue_url, false, $context );
            if ($result === FALSE) {
                throw new Exception('Failed to add fields to pull request.');
            }
        }
    }

    // tell dependencies.io that this one got updated successfully
    $schema_output = json_encode(array('dependencies' => array($dependency)));
    echo("BEGIN_DEPENDENCIES_SCHEMA_OUTPUT>$schema_output<END_DEPENDENCIES_SCHEMA_OUTPUT\n");
}
