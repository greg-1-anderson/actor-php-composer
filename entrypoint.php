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
$git_host = getenv('GIT_HOST');
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

    if ($git_host == 'github') {
        $github_api_token = getenv('GITHUB_API_TOKEN');
        $github_repo_full_name = getenv('GITHUB_REPO_FULL_NAME');
        $github_pr_labels = getenv('SETTING_GITHUB_PR_LABELS');
        $github_pr_labels = $github_pr_labels ? json_decode($github_pr_labels, true) : null;
        $github_pr_assignees = getenv('SETTING_GITHUB_PR_ASSIGNEES');
        $github_pr_assignees = $github_pr_assignees ? json_decode($github_pr_assignees, true) : null;
        $github_pr_milestone = getenv('SETTING_GITHUB_PR_MILESTONE');
        $github_pr_milestone = $github_pr_milestone ? (int) $github_pr_milestone : null;

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
        if ($github_pr_labels !== null) {
            $issue_fields['labels'] = $github_pr_labels;
        }
        if ($github_pr_assignees !== null) {
            $issue_fields['labels'] = $github_pr_assignees;
        }
        if ($github_pr_milestone !== null) {
            $issue_fields['milestone'] = $github_pr_milestone;
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
    } else if ($git_host == 'gitlab') {
        $gitlab_project_api_url = getenv('GITLAB_API_URL');
        $gitlab_api_token = getenv('GITLAB_API_TOKEN');
        $gitlab_assignee_id = getenv('SETTING_GITLAB_ASSIGNEE_ID');
        $gitlab_assignee_id = $gitlab_assignee_id ? intval($gitlab_assignee_id) : null;
        $gitlab_labels = getenv('SETTING_GITLAB_LABELS');
        $gitlab_labels = $gitlab_labels ? implode(',', json_decode($gitlab_labels, true)) : null;
        $gitlab_milestone_id = getenv('SETTING_GITLAB_MILESTONE_ID');
        $gitlab_milestone_id = $gitlab_milestone_id ? intval($gitlab_milestone_id) : null;
        $gitlab_target_project_id = getenv('SETTING_GITLAB_TARGET_PROJECT_ID');
        $gitlab_target_project_id = $gitlab_target_project_id ? intval($gitlab_target_project_id) : null;
        $gitlab_remove_source_branch = getenv('SETTING_GITLAB_REMOVE_SOURCE_BRANCH');
        $gitlab_remove_source_branch = $gitlab_remove_source_branch ? json_decode($gitlab_remove_source_branch, true) : null;

        $pr_data = array(
            'title' => $message,
            'description' => $pr_body,
            'target_branch' => $base_branch,
            'source_branch' => $branch_name
        );

        $options = array(
          'http' => array(
            'method'  => 'POST',
            'content' => json_encode( $pr_data ),
            'header'=>  "Content-Type: application/json\r\n" .
                        "Accept: application/json\r\n" .
                        "PRIVATE-TOKEN: $gitlab_api_token\r\n"
            )
        );

        $url = $gitlab_project_api_url . '/merge_requests';

        $context  = stream_context_create( $options );
        $result = file_get_contents( $url, false, $context );
        if ($result === FALSE) {
            throw new Exception('Failed to create pull request.');
        }
        $response = json_decode($result, true);
    }

    // tell dependencies.io that this one got updated successfully
    $schema_output = json_encode(array('dependencies' => array($dependency)));
    echo("BEGIN_DEPENDENCIES_SCHEMA_OUTPUT>$schema_output<END_DEPENDENCIES_SCHEMA_OUTPUT\n");
}
