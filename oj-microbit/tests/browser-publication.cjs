module.exports.savePublished = async function savePublished(page, mode = 'current') {
  await page.getByRole('button', { name: '과제 저장', exact: true }).click();
  const review = page.locator('[data-mb-publication-review]'); await review.waitFor();
  await review.locator('[data-mb-field="publicationReview.starterMode"]').selectOption(mode);
  await review.getByRole('button', { name: '공개 내용 점검하기', exact: true }).click();
  await review.getByRole('button', { name: '확인하고 공개', exact: true }).waitFor({ timeout: 60000 });
  await review.locator('input[type=checkbox]').check();
  await review.getByRole('button', { name: '확인하고 공개', exact: true }).click();
  await page.getByText('점검한 내용으로 과제를 공개했습니다.', { exact: true }).waitFor({ timeout: 60000 });
};
