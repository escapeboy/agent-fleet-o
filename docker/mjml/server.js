const express = require('express');
const mjml2html = require('mjml');

const app = express();
app.use(express.json({ limit: '5mb' }));

app.post('/v1/render', (req, res) => {
  const { mjml } = req.body;
  if (!mjml) {
    return res.status(400).json({ error: 'Missing mjml field' });
  }

  try {
    const result = mjml2html(mjml, { minify: false });
    res.json({ html: result.html, errors: result.errors });
  } catch (e) {
    res.status(422).json({ error: e.message });
  }
});

app.get('/healthcheck', (_, res) => res.json({ status: 'ok' }));

app.listen(15500, '0.0.0.0', () => {
  console.log('MJML server listening on :15500');
});
