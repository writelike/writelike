<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize error array
$errors = [];

$logo_outputted = 0;

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kluster.ai API configuration
    $api_key = ''; // Replace with your actual API key
    $api_url = 'https://api.kluster.ai/v1/chat/completions';
    $batch_api_url = 'https://api.kluster.ai/v1/batches';

    // Supported models and their max character limits (based on context window, 1 token ≈ 4 chars)
    /* $model_limits = [
        'klusterai/Meta-Llama-3.1-8B-Instruct-Turbo' => 524000, // 131K tokens
        'klusterai/Meta-Llama-3.3-70B-Instruct-Turbo' => 524000, // 131K tokens
        'klusterai/Llama-4-Maverick-FP8' => 4000000, // 1M tokens
        'klusterai/Llama-4-Scout' => 524000, // 131K tokens
        'deepseek-ai/DeepSeek-R1-0528' => 656000, // 164K tokens
        'deepseek-ai/DeepSeek-V3-0324' => 524000, // 131K tokens
        'qwen/Qwen3-235B-Reasoning-FP8' => 524000 // 131K tokens
    ];*/
    $model_limits = [
    'deepseek-ai/DeepSeek-R1' => 656000, // Estimated 164K tokens
    'deepseek-ai/DeepSeek-R1-0528' => 656000, // Estimated 164K tokens
    'deepseek-ai/DeepSeek-V3-0324' => 524000, // Estimated 131K tokens
    'google/gemma-3-27b-it' => 524000, // Estimated 131K tokens
    'mistralai/Magistral-Small-2506' => 512000, // Estimated 128K tokens
    'klusterai/Meta-Llama-3.1-8B-Instruct-Turbo' => 524000, // 131K tokens
    'klusterai/Meta-Llama-3.3-70B-Instruct-Turbo' => 524000, // 131K tokens
    'meta-llama/Llama-4-Maverick-17B-128E-Instruct-FP8' => 4000000, // 1M tokens
    'meta-llama/Llama-4-Scout-17B-16E-Instruct' => 40000000, // 10M tokens
    'mistralai/Mistral-Nemo-Instruct-2407' => 524000, // Estimated 131K tokens
    'mistralai/Mistral-Small-24B-Instruct-2501' => 512000, // Estimated 128K tokens
    'Qwen/Qwen2.5-VL-7B-Instruct' => 128000, // 32K tokens
    'Qwen/Qwen3-235B-A22B-FP8' => 524000, // Estimated 131K tokens
];

    // Get form inputs
    $selected_model = $_POST['model'] ?? '';
    $textarea_content = $_POST['textarea'] ?? '';
    $uploaded_files = $_FILES['files'] ?? [];

    // Validate inputs
    if (!array_key_exists($selected_model, $model_limits)) {
        $errors[] = 'Please select a valid model.';
    }
    if (empty($textarea_content)) {
        $errors[] = 'Textarea content is required.';
    }
    if (empty($uploaded_files) || $uploaded_files['error'][0] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'At least one file is required.';
    }

    // Set max characters based on selected model
    $max_chars = $model_limits[$selected_model] ?? 524000; // Default to smallest limit if invalid

    // Function to extract text from PDF (basic implementation using pdftotext if available)
    function extractTextFromPDF($file_path) {
        // Check if pdftotext is available
        if (!shell_exec('which pdftotext')) {
            return "Error: pdftotext not installed. Please install poppler-utils or upload TXT files.";
        }
        $output_file = tempnam(sys_get_temp_dir(), 'pdf_');
        shell_exec("pdftotext " . escapeshellarg($file_path) . " " . escapeshellarg($output_file));
        $text = file_get_contents($output_file);
        unlink($output_file);
        return $text ?: "Error extracting text from PDF.";
    }

    // Function to extract text from TXT
    function extractTextFromTXT($file_path) {
        return file_get_contents($file_path);
    }

    // Process uploaded files if no validation errors
    $combined_text = '';
    $max_file_size = 100 * 1024 * 1024; // 100 MB in bytes
    if (empty($errors)) {
        foreach ($uploaded_files['tmp_name'] as $index => $tmp_name) {
            if ($uploaded_files['error'][$index] === UPLOAD_ERR_OK) {
                $file_size = $uploaded_files['size'][$index];
                $file_type = $uploaded_files['type'][$index];
                $file_name = $uploaded_files['name'][$index];

                // Validate file size
                if ($file_size > $max_file_size) {
                    $errors[] = "File $file_name exceeds 100 MB limit.";
                    continue;
                }

                // Extract text based on file type
                if ($file_type === 'application/pdf') {
                    $text = extractTextFromPDF($tmp_name);
                    if (strpos($text, 'Error') === 0) {
                        $errors[] = $text;
                        continue;
                    }
                    $combined_text .= $text . "\n";
                } elseif ($file_type === 'text/plain') {
                    $combined_text .= extractTextFromTXT($tmp_name) . "\n";
                } else {
                    $errors[] = "Unsupported file type for $file_name. Only PDF and TXT are allowed.";
                }
            } else {
                $errors[] = "Error uploading file: $file_name.";
            }
        }
    }

    // Check if text was trimmed
    $original_length = strlen($combined_text);
    $was_trimmed = $original_length > $max_chars;
    if ($was_trimmed) {
        $combined_text = substr($combined_text, 0, $max_chars);
    }

    // Proceed with API call if no errors
    if (empty($errors)) {
        // Prepare JSONL file for batch processing
        $jsonl_file = 'batch_requests.jsonl';
        $requests = [
            [
                'custom_id' => 'rewrite-1',
                'method' => 'POST',
                'url' => '/v1/chat/completions',
                'body' => [
                    'model' => $selected_model,
                    'temperature' => 0.7,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a helpful assistant that rewrites text in the style of provided documents. Analyze the style, tone, and structure of the uploaded documents and rewrite the input text accordingly.'
                        ],
                        [
                            'role' => 'user',
                            'content' => "Documents to analyze for style:\n$combined_text\n\nText to rewrite:\n$textarea_content"
                        ]
                    ]
                ]
            ]
        ];

        // Write JSONL file
        $file_handle = fopen($jsonl_file, 'w');
        foreach ($requests as $request) {
            fwrite($file_handle, json_encode($request) . "\n");
        }
        fclose($file_handle);

        // Upload JSONL file to kluster.ai using cURL
        $ch = curl_init();
        $cfile = new CURLFile($jsonl_file, 'application/json', 'batch_requests.jsonl');
        curl_setopt($ch, CURLOPT_URL, 'https://api.kluster.ai/v1/files');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $api_key"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => $cfile,
            'purpose' => 'batch'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $errors[] = "Error uploading JSONL file: " . curl_error($ch);
        }
        curl_close($ch);
        $file_data = json_decode($response, true);
        if (!$file_data || !isset($file_data['id'])) {
            $errors[] = "Error uploading JSONL file: Invalid response from server.";
        }

        // Create batch job if no errors
        if (empty($errors)) {
            $file_id = $file_data['id'];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $batch_api_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $api_key",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'input_file_id' => $file_id,
                'endpoint' => '/v1/chat/completions',
                'completion_window' => '24h'
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $errors[] = "Error creating batch job: " . curl_error($ch);
            }
            curl_close($ch);
            $batch_data = json_decode($response, true);
            if (!$batch_data || !isset($batch_data['id'])) {
                $errors[] = "Error creating batch job: Invalid response from server.";
            }
        }

        // Poll for batch job completion if no errors
        if (empty($errors)) {
            $batch_id = $batch_data['id'];
            $status = 'pre_schedule';
            while ($status !== 'completed') {
                sleep(10);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://api.kluster.ai/v1/batches/$batch_id");
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $api_key"
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    $errors[] = "Error checking batch status: " . curl_error($ch);
                    break;
                }
                curl_close($ch);
                $batch_status = json_decode($response, true);
                $status = $batch_status['status'];
                if ($status === 'failed' || $status === 'cancelled') {
                    $errors[] = "Batch job failed or was cancelled.";
                    break;
                }
            }
        }

        // Retrieve output file if no errors
        if (empty($errors)) {
            $output_file_id = $batch_status['output_file_id'];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.kluster.ai/v1/files/$output_file_id/content");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $api_key"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $output_content = curl_exec($ch);
            if (curl_errno($ch)) {
                $errors[] = "Error retrieving output file: " . curl_error($ch);
            }
            curl_close($ch);
        }

        // Parse output JSONL if no errors
        $rewritten_text = '';
        if (empty($errors)) {
            $lines = explode("\n", $output_content);
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $result = json_decode($line, true);
                    if (isset($result['response']['body']['choices'][0]['message']['content'])) {
                        $rewritten_text = $result['response']['body']['choices'][0]['message']['content'];
                        break;
                    }
                }
            }
            if (empty($rewritten_text)) {
                $errors[] = "No rewritten text found in the API response.";
            }
        }

        // Clean up
        if (file_exists($jsonl_file)) {
            unlink($jsonl_file);
        }
    }

    // Display errors or results
	?>
	<center><img src="output.png" style="max-height: 250px;"></center>
	<?php
	$logo_outputted = 1;
    if (!empty($errors)) {
        echo "<div style='color: red;'><h2>Errors</h2><ul>";
        foreach ($errors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul></div>";
    } else {
        // Output truncation message if applicable
        if ($was_trimmed) {
            echo "<p style='color: red;'>You uploaded " . number_format($original_length) . " chars, but only " . number_format($max_chars) . " chars are allowed, so your content has been truncated.</p>";
        }
        // Output result
        echo "<h2 style='color: white;'>Rewritten Text</h2>";
        echo "<pre style=\"white-space: pre-wrap; overflow-wrap: break-word; word-wrap: break-word;\">" . htmlspecialchars($rewritten_text) . "</pre>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WriteLike</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #1E2A3A;
        }
        h1 {
            text-align: center;
            color: #333;
        }
        form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        select, textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        .dropzone {
            border: 2px dashed #007bff;
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            margin-bottom: 15px;
            border-radius: 4px;
            cursor: pointer;
        }
        .dropzone.dragover {
            background: #e1f0ff;
            border-color: #0056b3;
        }
        .dropzone p {
            margin: 0;
            color: #555;
        }
        #file-list {
            margin-top: 10px;
            text-align: left;
            color: #333;
        }
        #file-list div {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
        }
        #file-list button {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        #file-list button:hover {
            background: #c82333;
        }
        input[type="submit"] {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        input[type="submit"]:hover {
            background: #0056b3;
        }
        input[type="submit"]:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        #loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 20px;
            border-radius: 8px;
            z-index: 1000;
        }
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        #size-warning {
            color: red;
            margin-bottom: 15px;
            display: none;
        }
    </style>
</head>
<body>
    <!-- <h1>Kluster.ai Text Rewriter</h1> -->
	

	<center>

	<?php if ($logo_outputted == 0) { ?>
	<img src="output.png" style="max-height: 250px;"><br/><br/>
	<?php } ?>
	</center>
	<p style="color: white;">WriteLike is a simple app that lets you rewrite text in the style of anybody for whom you have a writing sample. You know how you can tell an AI model 
	to rewrite text in the style of Shakespeare, or some other famous author? WriteLike lets you do that for literally anybody, you just need samples of their writing and the tone, style, etc will be mimicked. 
	You can upload an unlimited number of writing samples in PDF or TXT format (max 100MB each) - the more sample content you upload, the more accurate the rewrite will be. The total sample content that you can 
	upload is limited depending on the AI model you select, as shown in the dropdown menu below. Keep in mind the listed limits refer to the total amount of content that can be uploaded. For reference, the default model, 
	Meta Llama 4 Scout, allows you to upload a maximum of about 40M chars, which is roughly 30,000 pages of text.<br/><br/>
	Source: <a style="color:white" href="https://github.com/writelike">GitHub</a><br/>
	Contact: hi[at]writelike.io or find me on X (@h45hb4ng)
	</p>
	
    <form method="post" enctype="multipart/form-data" id="upload-form">
        <label for="model">Select Model:</label>
        <select name="model" id="model" required>
            <option value="">Select a model</option>
            <?php
            /* $supported_models = [
                'klusterai/Meta-Llama-3.1-8B-Instruct-Turbo' => ['label' => 'Llama 3.1 8B Instruct Turbo', 'max_chars' => '524,000', 'max_chars_raw' => 524000],
                'klusterai/Meta-Llama-3.3-70B-Instruct-Turbo' => ['label' => 'Llama 3.3 70B Instruct Turbo', 'max_chars' => '524,000', 'max_chars_raw' => 524000],
                'klusterai/Llama-4-Maverick-FP8' => ['label' => 'Llama 4 Maverick (FP8)', 'max_chars' => '4,000,000', 'max_chars_raw' => 4000000],
                'klusterai/Llama-4-Scout' => ['label' => 'Llama 4 Scout', 'max_chars' => '524,000', 'max_chars_raw' => 524000],
                'deepseek-ai/DeepSeek-R1-0528' => ['label' => 'DeepSeek R1', 'max_chars' => '656,000', 'max_chars_raw' => 656000],
                'deepseek-ai/DeepSeek-V3-0324' => ['label' => 'DeepSeek V3', 'max_chars' => '524,000', 'max_chars_raw' => 524000],
                'qwen/Qwen3-235B-Reasoning-FP8' => ['label' => 'Qwen3 235B Reasoning (FP8)', 'max_chars' => '524,000', 'max_chars_raw' => 524000]
            ]; */
	    $supported_models = [
    'deepseek-ai/DeepSeek-R1' => ['label' => 'DeepSeek-R1', 'max_chars' => '656,000', 'max_chars_raw' => 656000], // Estimated 164K tokens
    'deepseek-ai/DeepSeek-R1-0528' => ['label' => 'DeepSeek-R1-0528', 'max_chars' => '656,000', 'max_chars_raw' => 656000], // Estimated 164K tokens
    'deepseek-ai/DeepSeek-V3-0324' => ['label' => 'DeepSeek-V3-0324', 'max_chars' => '524,000', 'max_chars_raw' => 524000], // Estimated 131K tokens
    'google/gemma-3-27b-it' => ['label' => 'Gemma 3 27B', 'max_chars' => '524,000', 'max_chars_raw' => 524000], // Estimated 131K tokens
    'mistralai/Magistral-Small-2506' => ['label' => 'Magistral Small', 'max_chars' => '512,000', 'max_chars_raw' => 512000], // Estimated 128K tokens
    'klusterai/Meta-Llama-3.1-8B-Instruct-Turbo' => ['label' => 'Meta Llama 3.1 8B', 'max_chars' => '524,000', 'max_chars_raw' => 524000], // 131K tokens
    'klusterai/Meta-Llama-3.3-70B-Instruct-Turbo' => ['label' => 'Meta Llama 3.3 70B', 'max_chars' => '524,000', 'max_chars_raw' => 524000], // 131K tokens
    'meta-llama/Llama-4-Maverick-17B-128E-Instruct-FP8' => ['label' => 'Meta Llama 4 Maverick', 'max_chars' => '4,000,000', 'max_chars_raw' => 4000000], // 1M tokens
    'meta-llama/Llama-4-Scout-17B-16E-Instruct' => ['label' => 'Meta Llama 4 Scout', 'max_chars' => '40,000,000', 'max_chars_raw' => 40000000], // 10M tokens
    'mistralai/Mistral-Nemo-Instruct-2407' => ['label' => 'Mistral NeMo', 'max_chars' => '524,000', 'max_chars_raw' => 524000], // Estimated 131K tokens
    'mistralai/Mistral-Small-24B-Instruct-2501' => ['label' => 'Mistral Small', 'max_chars' => '512,000', 'max_chars_raw' => 512000], // Estimated 128K tokens
    'Qwen/Qwen2.5-VL-7B-Instruct' => ['label' => 'Qwen2.5-VL 7B', 'max_chars' => '128,000', 'max_chars_raw' => 128000], // 32K tokens
    'Qwen/Qwen3-235B-A22B-FP8' => ['label' => 'Qwen3-235B-A22B', 'max_chars' => '524,000', 'max_chars_raw' => 524000], // Estimated 131K tokens
];

            foreach ($supported_models as $value => $data) {
                if  ($value == "meta-llama/Llama-4-Scout-17B-16E-Instruct")
			echo "<option selected value='$value' data-max-chars='{$data['max_chars_raw']}'>{$data['label']} ({$data['max_chars']} chars)</option>";
		else 
			echo "<option value='$value' data-max-chars='{$data['max_chars_raw']}'>{$data['label']} ({$data['max_chars']} chars)</option>";
            }
            ?>
        </select>

        <label for="files">Upload Files (PDF or TXT, max 100 MB each):</label>
        <div class="dropzone" id="dropzone">
            <p>Drag and drop PDF or TXT files here</p>
            <p>or</p>
            <input type="button" value="Browse Files" onclick="document.getElementById('file-input').click();" />
            <input type="file" name="files[]" id="file-input" multiple accept=".pdf,.txt" style="display: none;" required>
        </div>
        <div id="file-list"></div>
        <div id="size-warning"></div>

        <label for="textarea">Text to Rewrite:</label>
        <textarea name="textarea" id="textarea" required></textarea>

        <input type="submit" value="Rewrite Text" id="submit-button">
    </form>

    <div id="loading">
        <div class="spinner"></div>
        Processing your request...
    </div>

    <script>
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('file-input');
        const fileList = document.getElementById('file-list');
        const form = document.getElementById('upload-form');
        const submitButton = document.getElementById('submit-button');
        const loadingIndicator = document.getElementById('loading');
        const modelSelect = document.getElementById('model');
        const sizeWarning = document.getElementById('size-warning');
        let selectedFiles = [];

        // Handle drag-and-drop events
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            handleFiles(files);
        });

        // Handle file input change (Browse button)
        fileInput.addEventListener('change', () => {
            handleFiles(fileInput.files);
        });

        // Process selected or dropped files
        function handleFiles(files) {
            const validTypes = ['application/pdf', 'text/plain'];
            let newFiles = Array.from(files).filter(file => validTypes.includes(file.type));

            if (newFiles.length === 0 && files.length > 0) {
                alert('Please select valid PDF or TXT files.');
                return;
            }

            // Add new files to selectedFiles
            selectedFiles = [...selectedFiles, ...newFiles];
            updateFileInput();
            updateFileList();
            estimateTextSize();
        }

        // Update file input with selected files
        function updateFileInput() {
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
        }

        // Update file list display
        function updateFileList() {
            fileList.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const fileItem = document.createElement('div');
                const fileName = document.createElement('span');
                fileName.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                const removeButton = document.createElement('button');
                removeButton.textContent = 'Remove';
                removeButton.onclick = () => removeFile(index);
                fileItem.appendChild(fileName);
                fileItem.appendChild(removeButton);
                fileList.appendChild(fileItem);
            });
        }

        // Remove file from list
        function removeFile(index) {
            selectedFiles.splice(index, 1);
            updateFileInput();
            updateFileList();
            estimateTextSize();
        }

        // Estimate text size and show warning if needed
        function estimateTextSize() {
            const maxChars = parseInt(modelSelect.selectedOptions[0]?.dataset.maxChars) || 524000;
            let totalChars = 0;

            const promises = selectedFiles.map(file => {
                return new Promise((resolve) => {
                    if (file.type === 'text/plain') {
                        const reader = new FileReader();
                        reader.onload = () => {
                            totalChars += reader.result.length;
                            resolve();
                        };
                        reader.readAsText(file);
                    } else if (file.type === 'application/pdf') {
                        // Approximate: 10,000 chars per MB for PDFs (adjust based on typical PDF content)
                        totalChars += (file.size / 1024 / 1024) * 10000;
                        resolve();
                    } else {
                        resolve();
                    }
                });
            });

            Promise.all(promises).then(() => {
                if (totalChars > maxChars) {
                    sizeWarning.style.display = 'block';
                    sizeWarning.textContent = `Estimated text size (${numberWithCommas(totalChars)} chars) exceeds the selected model's limit (${numberWithCommas(maxChars)} chars). Consider removing files or selecting a model with a larger limit.`;
                } else {
                    sizeWarning.style.display = 'none';
                }
            });
        }

        // Format numbers with commas
        function numberWithCommas(x) {
            return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        // Update size estimation when model changes
        modelSelect.addEventListener('change', estimateTextSize);

        // Ensure valid inputs and show loading indicator on submission
        form.addEventListener('submit', (e) => {
            if (fileInput.files.length === 0) {
                e.preventDefault();
                alert('Please upload at least one PDF or TXT file.');
                return;
            }
            if (!modelSelect.value) {
                e.preventDefault();
                alert('Please select a model.');
                return;
            }
            if (!document.getElementById('textarea').value) {
                e.preventDefault();
                alert('Please enter text to rewrite.');
                return;
            }
            submitButton.disabled = true;
            loadingIndicator.style.display = 'block';
        });
    </script>
</body>
</html>
