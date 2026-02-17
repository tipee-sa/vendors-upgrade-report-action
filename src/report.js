const { execSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');

const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

async function withRetry(fn, label, retries = 3) {
  for (let attempt = 1; attempt <= retries; attempt++) {
    try {
      return await fn();
    } catch (e) {
      if (attempt === retries) throw e;
      console.log(`${label}: attempt ${attempt} failed (${e.message}), retrying in ${attempt * 2}s...`);
      await sleep(attempt * 2000);
    }
  }
}

async function fetchAllComments(github, context) {
  return github.paginate(github.rest.issues.listComments, {
    owner: context.repo.owner,
    repo: context.repo.repo,
    issue_number: context.issue.number,
    per_page: 100,
  });
}

/**
 * Generate and post an upgrade report for a single lock file.
 *
 * Compares the lock file at `baseRef` with the current version, generates a
 * markdown report via the PHP script, then creates/updates/deletes PR comments
 * (one per vendor section) to keep the report in sync.
 */
async function processLockFile({
  github,
  context,
  allComments,
  lockFile,
  baseRef,
  lockType,
  reportType,
  phpScript,
  appendFilePath,
  filePath,
}) {
  const markerBase = `<!-- ${reportType}-upgrade-report`;

  // Hash the current lock file to detect changes
  const lockHash = crypto.createHash('sha256').update(fs.readFileSync(lockFile)).digest('hex');

  // Find existing comments for this report type
  const existingComments = allComments
    .filter(c => c.body.startsWith(markerBase))
    .sort((a, b) => a.id - b.id);

  const escapedBase = markerBase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  // Check if report is already up to date
  if (existingComments.length > 0) {
    const hashRegex = new RegExp(`${escapedBase}:[\\w@\\/-]+ ([a-f0-9]+) total:(\\d+) -->`);
    const hashMatch = existingComments[0].body.match(hashRegex);
    if (hashMatch && hashMatch[1] === lockHash && existingComments.length === parseInt(hashMatch[2])) {
      console.log(`Report for ${filePath} already up to date`);
      return;
    }
  }

  // Extract old lock file from base ref
  const tmpDir = os.tmpdir();
  const oldLockPath = path.join(tmpDir, `old-${reportType}.lock`);

  try {
    execSync(`git show "${baseRef}:${filePath}" > "${oldLockPath}"`, { stdio: 'pipe' });
  } catch (e) {
    console.log(`${filePath} is new in this PR, skipping report`);
    return;
  }

  // Generate report
  const flag = lockType === 'yarn' ? '--from-yarn-lock' : '--from-lock';
  const reportPath = path.join(tmpDir, `${reportType}-report.md`);
  const logPath = path.join(tmpDir, `${reportType}-report.log`);

  try {
    execSync(`php "${phpScript}" ${flag} "${oldLockPath}" "${lockFile}" > "${reportPath}" 2>"${logPath}"`, {
      stdio: 'pipe',
    });
  } catch (e) {
    // Non-zero exit is expected when no upgrades are found
  }

  try {
    console.log(fs.readFileSync(logPath, 'utf8'));
  } catch (e) {
    // Log file may not exist
  }

  let report = '';
  try {
    report = fs.readFileSync(reportPath, 'utf8');
  } catch (e) {
    // Report file may not exist
  }

  if (!report.trim()) {
    console.log(`No upgrades found in ${filePath}`);
    for (const c of existingComments) {
      await withRetry(
        () => github.rest.issues.deleteComment({ owner: context.repo.owner, repo: context.repo.repo, comment_id: c.id }),
        'cleanup',
      );
      await sleep(500);
    }
    return;
  }

  // Append file path to vendor headings when there are multiple yarn.lock files
  if (appendFilePath && filePath) {
    report = report.replace(/^(# .+)$/gm, `$1 — \`${filePath}\``);
  }

  // Split report by vendor sections
  const vendorSections = report.split(/(?=<!-- vendor-section:)/).filter(s => s.trim());

  const vendors = [];
  for (const section of vendorSections) {
    const nameMatch = section.match(/<!-- vendor-section:(.+?) -->/);
    if (nameMatch) {
      vendors.push({
        name: nameMatch[1],
        content: section.replace(/<!-- vendor-section:.+? -->\n/, ''),
      });
    }
  }

  console.log(`${filePath}: ${vendors.length} vendor section(s)`);

  // Map existing comments to vendor names
  const existingByVendor = {};
  for (const c of existingComments) {
    const m = c.body.match(new RegExp(`${escapedBase}:([\\w@\\/-]+) `));
    if (m) {
      existingByVendor[m[1]] = c;
    }
  }

  const processedVendors = new Set();
  const totalVendors = vendors.length;

  // Update or create comments for each vendor
  for (const vendor of vendors) {
    processedVendors.add(vendor.name);
    const marker = `<!-- ${reportType}-upgrade-report:${vendor.name} ${lockHash} total:${totalVendors} -->`;
    const body = marker + '\n' + vendor.content;
    const existing = existingByVendor[vendor.name];

    if (existing) {
      await withRetry(
        () =>
          github.rest.issues.updateComment({
            owner: context.repo.owner,
            repo: context.repo.repo,
            comment_id: existing.id,
            body,
          }),
        vendor.name,
      );
      console.log(`Updated comment #${existing.id} for ${vendor.name}`);
    } else {
      const { data: created } = await withRetry(
        () =>
          github.rest.issues.createComment({
            owner: context.repo.owner,
            repo: context.repo.repo,
            issue_number: context.issue.number,
            body,
          }),
        vendor.name,
      );
      console.log(`Created comment #${created.id} for ${vendor.name}`);
    }

    await sleep(500);
  }

  // Delete stale vendor comments that no longer exist in the report
  for (const [vendorName, comment] of Object.entries(existingByVendor)) {
    if (!processedVendors.has(vendorName)) {
      await withRetry(
        () =>
          github.rest.issues.deleteComment({
            owner: context.repo.owner,
            repo: context.repo.repo,
            comment_id: comment.id,
          }),
        vendorName,
      );
      console.log(`Deleted stale comment #${comment.id} for ${vendorName}`);
    }
  }
}

module.exports = async function ({ github, context, core }) {
  const phpScript = process.env.PHP_SCRIPT;
  const composerLock = process.env.COMPOSER_LOCK || '';
  const yarnLockFiles = JSON.parse(process.env.YARN_LOCK_FILES || '[]');
  const baseRef = process.env.BASE_REF;

  // Fetch all PR comments once, shared across all lock files
  const allComments = await fetchAllComments(github, context);

  // Process Composer lock file
  if (composerLock) {
    console.log('\n=== Processing Composer ===');
    await processLockFile({
      github,
      context,
      allComments,
      lockFile: composerLock,
      baseRef,
      lockType: 'composer',
      reportType: 'composer',
      phpScript,
      appendFilePath: false,
      filePath: composerLock,
    });
  }

  // Process Yarn lock files
  for (const yarnFile of yarnLockFiles) {
    console.log(`\n=== Processing ${yarnFile} ===`);
    const reportType = yarnFile.replace(/\//g, '-').replace('.lock', '');

    await processLockFile({
      github,
      context,
      allComments,
      lockFile: yarnFile,
      baseRef,
      lockType: 'yarn',
      reportType,
      phpScript,
      appendFilePath: yarnLockFiles.length > 1,
      filePath: yarnFile,
    });
  }
};
